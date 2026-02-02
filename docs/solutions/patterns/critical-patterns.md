# Critical Patterns - Required Reading

**Purpose:** This document contains critical patterns that MUST be followed to avoid recurring issues. Each pattern represents a mistake that was made multiple times or has significant impact.

**Audience:** All developers and AI agents working on this codebase should review these patterns before making changes.

---

## 1. Test Environment Schema Setup (ALWAYS REQUIRED)

### ❌ WRONG (Schema setup as CI-specific step)

```yaml
# .github/workflows/tests.yml
- name: Setup database schema
  continue-on-error: true  # Masks failures!
  run: |
    mysql -h 127.0.0.1 -u user -ppass database < migrations/schema.sql

- name: Run tests
  run: vendor/bin/phpunit
```

**Problems with this approach:**
- CI-specific setup creates environment parity issues
- `continue-on-error: true` masks schema creation failures
- Local developers don't run the same setup
- Tests pass locally but fail in CI (or vice versa)

### ✅ CORRECT (Schema setup in test bootstrap)

```php
// tests/Functional/FunctionalTestCase.php
abstract class FunctionalTestCase extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Setup database schema once for entire test suite
        if (!self::$schemaInitialized) {
            self::setupDatabaseSchema();
            self::$schemaInitialized = true;
        }
    }

    private static function setupDatabaseSchema(): void
    {
        $schemaFile = __DIR__.'/schema.sql';
        $databaseUrl = $_ENV['DATABASE_URL'] ?? 'mysql://user:pass@127.0.0.1:3306/test_db';

        // Parse DATABASE_URL
        $parts = parse_url($databaseUrl);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'root';
        $pass = $parts['pass'] ?? '';
        $dbname = ltrim($parts['path'] ?? '/test_db', '/');

        // SAFETY CHECK: Only run on test databases
        if (!str_contains($dbname, '_test')) {
            throw new \RuntimeException(
                sprintf('SAFETY CHECK: Database must contain "_test". Got: %s', $dbname)
            );
        }

        // Wait for database to be ready
        $maxRetries = 30;
        $pdo = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $pdo = new \PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname),
                    $user,
                    $pass,
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                    ]
                );
                break;
            } catch (\PDOException $e) {
                if ($i === $maxRetries - 1) {
                    throw new \RuntimeException(
                        sprintf('Failed to connect after %d attempts: %s', $maxRetries, $e->getMessage())
                    );
                }
                sleep(1);
            }
        }

        // Execute schema file
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('Failed to read schema file: '.$schemaFile);
        }

        $pdo->exec($schema);

        // Verify critical tables exist
        $stmt = $pdo->query("SHOW TABLES LIKE 'critical_table'");
        if ($stmt->fetch() === false) {
            throw new \RuntimeException('Schema applied but critical_table not found');
        }
    }
}
```

**Why:** This approach ensures:
- **Environment parity**: CI and local use identical schema setup
- **Fail-fast**: Errors in schema setup cause immediate test failure (not masked)
- **One-time execution**: Schema setup runs once per test suite (not per test)
- **Safety checks**: Prevents accidental production database modification
- **Database readiness**: Waits for database to be available (handles Docker startup timing)

**Placement/Context:**
- Required for any test suite that needs database tables
- Applies to functional/integration tests (not unit tests)
- Use `setUpBeforeClass()` for one-time execution per test class
- Create test-specific schema file (e.g., `tests/Functional/schema.sql`) separate from production migrations

**Additional Requirements:**
1. **Separate test schema from production migrations:**
   - Production: `migrations/schema.sql` (only application-managed tables)
   - Tests: `tests/Functional/schema.sql` (all tables with DROP/CREATE)

2. **Remove CI-specific schema setup steps:**
   - Don't run `mysql < schema.sql` in CI workflows
   - Let test bootstrap handle it uniformly

3. **Test with fresh environment before pushing:**
   ```bash
   docker compose down -v  # Clean slate
   docker compose up -d
   vendor/bin/phpunit      # Should work from scratch
   ```

**Documented in:** `docs/solutions/test-failures/fresh-environment-schema-setup-20260131.md`

---

## 2. [Next Pattern] (Add future patterns below)

[Future critical patterns go here]
