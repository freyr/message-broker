# Code Review: AMQP Topology Setup Command

**Date:** 2026-02-08
**Branch:** `15-amqp-topology-setup-command`
**Issue:** #15
**Files changed:** 11 files, +1,707/-1 lines
**Tests:** 38 new tests (72 total), 129 new assertions (343 total) — all passing

## Review Agents Used

- `pattern-recognition-specialist` — Design patterns, duplication, naming conventions
- `architecture-strategist` — SOLID principles, coupling, architectural fit
- `security-sentinel` — Credentials, file I/O, input validation
- `performance-oracle` — Algorithm complexity, I/O, scalability
- `code-simplicity-reviewer` — Dead code, YAGNI, simplification opportunities
- `git-history-analyzer` — Commit structure, conventions, atomicity

---

## Findings Summary

- **Total Findings:** 13
- **P1 (Critical):** 1 — should fix before merge
- **P2 (Important):** 6 — should fix
- **P3 (Nice-to-have):** 6 — can defer

---

## P1 — Critical (Should Fix Before Merge)

### 1. Credential leakage in DSN error messages

**Severity:** P1 — Security
**Files:** `src/Command/SetupAmqpTopologyCommand.php:206`, `src/Command/SetupAmqpTopologyCommand.php:128`
**Sources:** security-sentinel, architecture-strategist

The `createConnection()` method throws `InvalidArgumentException` with the full DSN embedded in the message:

```php
throw new \InvalidArgumentException(sprintf('Invalid AMQP DSN: "%s"', $dsn));
```

The DSN format is `amqp://username:password@host:port/vhost`, so credentials are directly exposed in:
- Console output (visible in terminal sessions, screen recordings, shared terminals)
- Log aggregation systems (ELK, Datadog, Sentry) if the exception propagates
- CI/CD pipeline output if the command runs during deployments

Additionally, the `AMQPConnectionException` message (line 128) is passed directly to console output via `$e->getMessage()`, which may contain connection details depending on ext-amqp version.

**Remediation:** Sanitise the DSN before including in any exception or log message:

```php
$safeDsn = preg_replace('#://[^@]+@#', '://***:***@', $dsn);
throw new \InvalidArgumentException(sprintf('Invalid AMQP DSN: "%s"', $safeDsn));
```

For the connection exception, provide a generic message to console output:

```php
$io->error('Failed to connect to RabbitMQ. Check DSN and network connectivity.');
```

---

## P2 — Important (Should Fix)

### 2. Dead code: no-op DLX scanning loop

**Severity:** P2 — Code quality
**File:** `src/Amqp/TopologyManager.php:148-155`
**Sources:** pattern-recognition-specialist, code-simplicity-reviewer

```php
foreach ($this->topology['queues'] as $queueConfig) {
    if (isset($queueConfig['arguments']['x-dead-letter-exchange'])) {
        // No dependency between exchanges — DLX just needs to be in the list
        // The topological sort handles this if exchanges reference each other
    }
}
```

An 8-line `foreach` loop with an empty `if` block. Does nothing at runtime. Looks like unfinished work to readers and confuses static analysis tools. The design decision it documents can be captured as a single comment.

**Remediation:** Remove the entire block. If the design note is worth preserving, add a one-line comment above `return $this->topologicalSort($dependencies);`.

### 3. DSN parsing logic duplicated

**Severity:** P2 — DRY violation
**File:** `src/Command/SetupAmqpTopologyCommand.php:181-229`
**Sources:** pattern-recognition-specialist, architecture-strategist, code-simplicity-reviewer

`resolveVhost()` (lines 181-200) and `createConnection()` (lines 202-229) both call `parse_url($dsn)` and extract the vhost with identical logic:

```php
// In resolveVhost():
$path = urldecode(ltrim($parsed['path'], '/'));
return $path !== '' ? $path : '/';

// In createConnection():
$vhost = urldecode(ltrim($parsed['path'], '/'));
$credentials['vhost'] = $vhost !== '' ? $vhost : '/';
```

**Remediation:** Extract a private `parseDsn(string $dsn): array` method that returns a structured array. Have both methods consume it.

### 4. `file_put_contents` return value unchecked

**Severity:** P2 — Reliability
**File:** `src/Command/SetupAmqpTopologyCommand.php:102`
**Sources:** security-sentinel, architecture-strategist, code-simplicity-reviewer

```php
file_put_contents($outputPath, $json."\n");
```

If the write fails (permissions, disk full, invalid path), the command reports success despite the file not being written. In deployment pipelines, this could lead to incomplete infrastructure provisioning.

**Remediation:**

```php
$result = file_put_contents($outputPath, $json . "\n");
if ($result === false) {
    $io->error(sprintf('Failed to write definitions to %s', $outputPath));
    return Command::FAILURE;
}
```

### 5. Static coupling: DefinitionsFormatter depends on TopologyManager

**Severity:** P2 — Coupling
**File:** `src/Amqp/DefinitionsFormatter.php:66`
**Sources:** pattern-recognition-specialist, architecture-strategist, code-simplicity-reviewer

```php
$arguments = TopologyManager::normaliseArguments($config['arguments']);
```

`DefinitionsFormatter` calls `TopologyManager::normaliseArguments()` as a `public static` method. This creates tight coupling between two classes that should be independently testable. The `public static` on `TopologyManager` (which otherwise has only instance methods) is an architectural inconsistency.

**Remediation options:**
- **Simplest (YAGNI):** Duplicate the 6-line method into `DefinitionsFormatter` as `private`, make the original `private` on `TopologyManager`.
- **Cleanest:** Extract a small `ArgumentNormaliser` utility class in the `Amqp` namespace.

### 6. Redundant no-op ternary

**Severity:** P2 — Code quality
**File:** `src/Amqp/TopologyManager.php:280`
**Sources:** pattern-recognition-specialist, code-simplicity-reviewer

```php
$arguments = $binding['arguments'] !== [] ? $binding['arguments'] : [];
```

This evaluates to `$binding['arguments']` in all cases. If the array is empty, the ternary returns `[]` (same value). If non-empty, returns itself.

**Remediation:** Remove the ternary. Pass `$binding['arguments']` directly to `$queue->bind()`.

### 7. Unreachable `default` match arm

**Severity:** P2 — YAGNI
**File:** `src/Command/SetupAmqpTopologyCommand.php:146`
**Sources:** code-simplicity-reviewer

```php
$label = match ($result['status']) {
    'created' => '<fg=green>[OK]</>',
    'error' => '<fg=red>[ERROR]</>',
    default => '<fg=yellow>[SKIP]</>',  // ← never produced
};
```

`TopologyManager::declare()` only ever returns `'created'` or `'error'`. No code path produces any other status. This is "just in case" code with no backing implementation.

**Remediation:** Remove the `default` arm. If a new status is needed later, add it then.

---

## P3 — Nice-to-Have (Can Defer)

### 8. `array_shift()` in topological sort — suboptimal complexity

**Severity:** P3 — Performance (cosmetic)
**File:** `src/Amqp/TopologyManager.php:345`
**Source:** performance-oracle

`array_shift()` on a PHP array is O(n) due to re-indexing. In the topological sort loop, this makes the algorithm O(n²). Using `SplQueue` would achieve textbook O(V + E).

**Practical impact:** Negligible. Realistic deployments have 2-50 exchanges. The difference is measured in microseconds, far below a single AMQP round-trip (~2-5ms).

**Remediation (if desired):**

```php
$queue = new \SplQueue();
// ... $queue->enqueue($node) instead of $queue[] = $node
// ... $queue->dequeue() instead of array_shift($queue)
// ... !$queue->isEmpty() instead of $queue !== []
```

### 9. Missing cross-entity validation in configuration

**Severity:** P3 — Fail-fast
**File:** `src/DependencyInjection/Configuration.php`, `src/DependencyInjection/FreyrMessageBrokerExtension.php`
**Source:** architecture-strategist, pattern-recognition-specialist

Bindings can reference exchange/queue names that do not exist in the `exchanges` or `queues` sections. These errors only surface at runtime when RabbitMQ rejects the bind. In `--dry-run` and `--dump` modes, they pass silently.

**Remediation:** Add validation in `FreyrMessageBrokerExtension::load()` or as a Symfony compiler pass to verify referential integrity.

### 10. `resolveExchangeOrder()` is public but is an implementation detail

**Severity:** P3 — API surface
**File:** `src/Amqp/TopologyManager.php:124`
**Source:** code-simplicity-reviewer

Only used internally by `declare()` and `dryRun()`. Public visibility is testing-driven (5 unit tests call it directly). Increases the class's public API surface unnecessarily.

**Remediation:** Consider making `private` and testing indirectly via `dryRun()`.

### 11. No connection timeout configuration

**Severity:** P3 — Operational
**File:** `src/Command/SetupAmqpTopologyCommand.php:202-228`
**Source:** security-sentinel

`AMQPConnection` is created without `connect_timeout` or `read_timeout`. Defaults can result in 60s+ blocking waits when RabbitMQ is unreachable, potentially hanging CI/CD pipelines.

**Remediation:** Add `connect_timeout` and `read_timeout` to the credentials array (e.g., 10 seconds default).

### 12. Missing class-level PHPDoc on SetupAmqpTopologyCommand

**Severity:** P3 — Consistency
**File:** `src/Command/SetupAmqpTopologyCommand.php:20`
**Source:** pattern-recognition-specialist

The command class has no class-level PHPDoc, unlike all other new classes and test files in this PR.

### 13. Git: production fix mixed into test commit

**Severity:** P3 — Git hygiene
**Commit:** `bd0f612` (typed `test:` but modifies production code)
**Source:** git-history-analyzer

The `normalizeKeys(false)` addition in `Configuration.php` is a production bug fix that was bundled into a `test:` commit. This breaks commit atomicity — cherry-picking or reverting the test commit would unexpectedly alter production behaviour.

**Remediation:** If rebasing is still an option, fold the `normalizeKeys(false)` fix into the first commit (`5dff76e`).

---

## Positive Observations

- **Clean architectural layer separation** — Domain logic (`TopologyManager`), presentation (`DefinitionsFormatter`), and CLI orchestration (`SetupAmqpTopologyCommand`) are properly separated.
- **Topological sort correctly implements Kahn's algorithm** with cycle detection, preventing infinite loops from circular exchange dependencies.
- **Comprehensive test coverage** — 38 tests with 129 assertions covering unit (dependency resolution, formatting, config validation) and functional (live RabbitMQ declaration, idempotency, dry-run, dump) scenarios.
- **British English consistently used** — `normaliseArguments`, `$normalised`, etc.
- **Idempotent declaration** verified in functional tests — running the command twice succeeds without errors.
- **Configuration tree validates** exchange types via `enumNode`, required fields, and provides sensible defaults.
- **Feature fills a real operational gap** — provides the manual AMQP setup tooling needed by the bundle's `auto_setup: false` policy.
- **`final readonly`** convention followed on all new classes (Command class is a legitimate exception due to Symfony's mutable base class).
- **`--dump` mode** provides a batch alternative to sequential AMQP declarations via `rabbitmqctl import_definitions`.

---

## Scalability Notes (from performance-oracle)

| Metric | 10 items | 100 items | 1,000 items |
|--------|----------|-----------|-------------|
| Topological sort (current) | ~0.01ms | ~0.1ms | ~5ms |
| AMQP declarations (network I/O) | ~50ms | ~500ms | ~5,000ms |
| Memory usage | ~50 KB | ~2 MB | ~20 MB |
| JSON output (pretty-printed) | ~5 KB | ~500 KB | ~5 MB |

Network I/O dominates all other costs by 3+ orders of magnitude. CPU-side optimisations are irrelevant to overall command execution time at any realistic scale.

---

## Architectural Assessment (from architecture-strategist)

| Aspect | Rating |
|--------|--------|
| Architectural fit | Strong — fills operational gap in `auto_setup: false` policy |
| Single Responsibility | Upheld — clean separation between declaration, formatting, orchestration |
| Coupling | Low — minor static coupling via `normaliseArguments()` |
| Configuration design | Well-designed — natural YAML ergonomics with appropriate defaults |
| Test coverage | Thorough — unit + functional, including live RabbitMQ |
| SOLID compliance | Strong overall, with minor DIP observation on DSN parsing |
| Technical debt introduced | Low |
