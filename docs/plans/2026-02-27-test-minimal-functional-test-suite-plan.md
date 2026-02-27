---
title: "test: add minimal functional test suite"
type: test
date: 2026-02-27
issue: "#30"
---

# test: add minimal functional test suite

## Overview

Add the minimal set of functional tests that prove guarantees unit tests cannot — specifically, real MySQL interactions for binary UUID v7 storage, deduplication via unique constraint, and cleanup SQL. No Symfony kernel required for any test; all use raw DBAL connections.

## Problem Statement

The package has 72 pure unit tests with all I/O mocked. The core safety guarantees (deduplication, binary UUID v7 storage, cleanup SQL) rely on MySQL-specific behaviour. If any of these break, the consequences are severe: duplicate message processing or data corruption. No test currently verifies these against a real database.

## Key Design Decisions

### 1. No Symfony Kernel — Raw DBAL Connections

All functional tests use `DriverManager::getConnection()` directly. This avoids the complexity of `TestKernel`, `test.yaml`, `KernelTestCase`, Doctrine ORM configuration, and `doctrine_transaction` middleware setup. The classes under test (`DeduplicationDbalStore`, `DeduplicationStoreCleanup`, `IdType`) only need a DBAL `Connection`.

**Rationale:** A Symfony kernel was the main source of complexity and fragility in the deleted functional tests. These tests verify database behaviour, not DI wiring.

### 2. Shared Base Class with DROP/CREATE Schema

A `FunctionalDatabaseTestCase` base class handles:
- DBAL connection construction from `DATABASE_URL` env var
- Safety check: database name must contain `_test`
- `setUpBeforeClass()`: DROP and CREATE tables (idempotent across runs)
- `setUp()`: TRUNCATE tables before each test method
- `IdType` registration with `Type::hasType()` guard

### 3. SetupDeduplicationCommand Uses a Separate Table Name

Flow 4 (`--force`) creates the table via the command itself. To avoid colliding with the shared schema from the base class, it uses a unique table name (`test_setup_cmd_deduplication`) and drops it in `tearDownAfterClass()`.

### 4. Serialiser Round-Trip Dropped from Functional Suite

The existing unit serialiser tests (`InboxSerializerTest`, `WireFormatSerializerTest`) already construct a real `Symfony\Component\Serializer\Serializer` with the full normaliser chain. They test encode/decode round-trips, header structure, and stamp propagation. A functional test without a DI container would duplicate this scope entirely.

### 5. CI: Dedicated Functional Job, Not in Matrix

A new `functional-tests` job with MySQL service runs `--testsuite Functional` once (PHP 8.4, Symfony 7.x). The existing matrix job is updated to `--testsuite Unit` explicitly. Functional tests do not need a full matrix — MySQL behaviour does not vary across PHP/Symfony versions.

## Technical Approach

### Infrastructure

```
tests/
├── Functional/
│   ├── FunctionalDatabaseTestCase.php     # Shared base: connection, schema, safety
│   ├── schema.sql                          # DROP/CREATE for deduplication table only
│   ├── DeduplicationDbalStoreTest.php      # Flow 1
│   ├── DeduplicationStoreCleanupTest.php   # Flow 2
│   ├── IdTypeRoundTripTest.php             # Flow 3
│   └── SetupDeduplicationCommandTest.php   # Flow 4
├── Fixtures/
│   ├── TestInboxEvent.php                  # (existing)
│   ├── TestOutboxEvent.php                 # (existing)
│   └── TestPublisher.php                   # (existing)
└── Unit/
    └── ...                                 # (existing, unchanged)
```

### Phase 1: Test Infrastructure

#### 1.1 Create `tests/Functional/schema.sql`

Only the deduplication table — this is the only table the functional tests need.

```sql
DROP TABLE IF EXISTS message_broker_deduplication;

CREATE TABLE message_broker_deduplication (
    message_id   BINARY(16)   NOT NULL PRIMARY KEY COMMENT '(DC2Type:id_binary)',
    message_name VARCHAR(255) NOT NULL,
    processed_at DATETIME     NOT NULL,
    INDEX idx_dedup_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 1.2 Create `tests/Functional/FunctionalDatabaseTestCase.php`

```php
abstract class FunctionalDatabaseTestCase extends TestCase
{
    private static bool $schemaInitialised = false;
    protected static Connection $connection;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Register IdType once (global singleton)
        if (!Type::hasType(IdType::NAME)) {
            Type::addType(IdType::NAME, IdType::class);
        }

        // Create DBAL connection from DATABASE_URL
        $databaseUrl = getenv('DATABASE_URL')
            ?: 'mysql://messenger:messenger@mysql:3306/messenger_test';
        self::$connection = DriverManager::getConnection(
            ['url' => $databaseUrl]
        );

        // Safety check
        $dbName = self::$connection->getDatabase();
        if (!str_contains($dbName, '_test')) {
            throw new RuntimeException(
                sprintf('SAFETY: Database must contain "_test". Got: %s', $dbName)
            );
        }

        // Schema setup (once per suite)
        if (!self::$schemaInitialised) {
            self::setupSchema();
            self::$schemaInitialised = true;
        }
    }

    private static function setupSchema(): void
    {
        // Wait for DB readiness (max 30s)
        $maxRetries = 30;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                self::$connection->executeQuery('SELECT 1');
                break;
            } catch (\Exception $e) {
                if ($i === $maxRetries - 1) {
                    throw new RuntimeException(
                        sprintf('DB not ready after %d attempts: %s', $maxRetries, $e->getMessage())
                    );
                }
                sleep(1);
            }
        }

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        self::$connection->executeStatement($schema);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$connection->executeStatement('TRUNCATE TABLE message_broker_deduplication');
    }
}
```

#### 1.3 Update `phpunit.xml.dist`

Add a `Functional` testsuite:

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Functional">
        <directory>tests/Functional</directory>
    </testsuite>
</testsuites>
```

#### 1.4 Update `.github/workflows/tests.yml`

- Existing matrix job: add `--testsuite Unit` to the `vendor/bin/phpunit` command
- New `functional-tests` job:

```yaml
functional-tests:
  name: Functional Tests
  runs-on: ubuntu-latest

  services:
    mysql:
      image: mysql:8.0
      env:
        MYSQL_ROOT_PASSWORD: root
        MYSQL_DATABASE: messenger_test
        MYSQL_USER: messenger
        MYSQL_PASSWORD: messenger
      ports:
        - 3306:3306
      options: >-
        --health-cmd="mysqladmin ping -h localhost"
        --health-interval=5s
        --health-timeout=3s
        --health-retries=10

  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.4'
        extensions: dom, curl, libxml, mbstring, zip, pcntl, bcmath, intl, pdo_mysql
        coverage: none
        tools: composer:v2
    - run: composer update --prefer-stable --prefer-dist --no-interaction --no-progress
    - name: Run functional tests
      env:
        APP_ENV: test
        DATABASE_URL: mysql://messenger:messenger@127.0.0.1:3306/messenger_test
      run: vendor/bin/phpunit --testsuite Functional --testdox
```

### Phase 2: Test Classes (Tier 1)

#### 2.1 `DeduplicationDbalStoreTest.php`

Tests `DeduplicationDbalStore` against real MySQL with binary UUID v7.

| Test | What it proves |
|---|---|
| `testNewMessageIsNotDuplicate` | INSERT succeeds with binary(16) UUID v7, returns `false` |
| `testSameMessageIdIsDuplicate` | Second INSERT with same UUID triggers `UniqueConstraintViolationException`, returns `true` |
| `testDifferentMessageIdsAreNotDuplicates` | Two different UUIDs both insert successfully |
| `testRowIsPersistedWithCorrectData` | Verify stored data: message_id (binary), message_name (string), processed_at (datetime) |

```php
final class DeduplicationDbalStoreTest extends FunctionalDatabaseTestCase
{
    private DeduplicationDbalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new DeduplicationDbalStore(self::$connection);
    }

    public function testNewMessageIsNotDuplicate(): void
    {
        $id = Id::new();
        $result = $this->store->isDuplicate($id, 'test.event');
        $this->assertFalse($result);
    }

    public function testSameMessageIdIsDuplicate(): void
    {
        $id = Id::new();
        $this->store->isDuplicate($id, 'test.event');

        $result = $this->store->isDuplicate($id, 'test.event');
        $this->assertTrue($result);
    }

    // ... additional tests
}
```

#### 2.2 `DeduplicationStoreCleanupTest.php`

Tests the cleanup command's `DATE_SUB` SQL against real MySQL.

| Test | What it proves |
|---|---|
| `testOldRecordsAreDeleted` | Rows with `processed_at` 60 days ago are deleted when `--days=30` |
| `testRecentRecordsAreKept` | Rows with `processed_at` now are kept when `--days=30` |
| `testReturnsDeletedCount` | Command output reports correct number of deleted rows |

"Old" rows are inserted directly via `$connection->insert()` with a hardcoded past timestamp (e.g., `'2000-01-01 00:00:00'`), bypassing `DeduplicationDbalStore` which always uses `date('Y-m-d H:i:s')`. This is intentional — the test exercises the SQL, not the store.

```php
final class DeduplicationStoreCleanupTest extends FunctionalDatabaseTestCase
{
    public function testOldRecordsAreDeletedAndRecentKept(): void
    {
        // Insert old row (well past any threshold)
        self::$connection->insert('message_broker_deduplication', [
            'message_id' => Id::new()->toBinary(),
            'message_name' => 'old.event',
            'processed_at' => '2000-01-01 00:00:00',
        ]);

        // Insert recent row
        self::$connection->insert('message_broker_deduplication', [
            'message_id' => Id::new()->toBinary(),
            'message_name' => 'recent.event',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $command = new DeduplicationStoreCleanup(self::$connection);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => 30]);

        $count = self::$connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication'
        );
        $this->assertSame(1, (int) $count, 'Only recent record should remain');
    }
}
```

#### 2.3 `IdTypeRoundTripTest.php`

Tests binary UUID v7 storage and retrieval through `IdType` against real MySQL.

Uses a dedicated test table `id_type_round_trip_test` (not the deduplication table) with `DROP TABLE IF EXISTS` in `setUpBeforeClass` and `tearDownAfterClass`.

| Test | What it proves |
|---|---|
| `testBinaryStorageAndRetrieval` | INSERT `Id::new()->toBinary()` → SELECT → `Id::fromBinary()` → same UUID string |
| `testMultipleIdsRoundTrip` | Several UUIDs stored and retrieved correctly (no corruption) |
| `testNullHandling` | NULL value round-trips correctly via `convertToPHPValue` |

```php
final class IdTypeRoundTripTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'id_type_round_trip_test';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$connection->executeStatement(
            sprintf('DROP TABLE IF EXISTS %s', self::TABLE)
        );
        self::$connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                id BINARY(16) NOT NULL PRIMARY KEY,
                label VARCHAR(50) NULL
            ) ENGINE=InnoDB',
            self::TABLE
        ));
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->executeStatement(
            sprintf('DROP TABLE IF EXISTS %s', self::TABLE)
        );
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        // Override parent — do NOT truncate deduplication table
        self::$connection->executeStatement(
            sprintf('TRUNCATE TABLE %s', self::TABLE)
        );
    }

    public function testBinaryStorageAndRetrieval(): void
    {
        $original = Id::new();
        $type = Type::getType(IdType::NAME);
        $platform = self::$connection->getDatabasePlatform();

        // Store
        self::$connection->insert(self::TABLE, [
            'id' => $type->convertToDatabaseValue($original, $platform),
            'label' => 'test',
        ]);

        // Retrieve
        $raw = self::$connection->fetchOne(
            sprintf('SELECT id FROM %s WHERE label = ?', self::TABLE),
            ['test']
        );

        $restored = $type->convertToPHPValue($raw, $platform);
        $this->assertTrue($original->sameAs($restored));
    }
}
```

### Phase 3: Test Class (Tier 2)

#### 3.1 `SetupDeduplicationCommandTest.php`

Tests `--force` mode against real MySQL. Uses a unique table name to avoid collision.

| Test | What it proves |
|---|---|
| `testForceCreatesTable` | Command creates table, introspect columns to verify schema |
| `testForceIsIdempotent` | Running twice returns SUCCESS without error |
| `testDryRunShowsSql` | Without `--force`, command outputs SQL without executing |

```php
final class SetupDeduplicationCommandTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'test_setup_cmd_deduplication';

    protected function setUp(): void
    {
        // Override parent — drop our specific table, not truncate deduplication
        self::$connection->executeStatement(
            sprintf('DROP TABLE IF EXISTS %s', self::TABLE)
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->executeStatement(
            sprintf('DROP TABLE IF EXISTS %s', self::TABLE)
        );
        parent::tearDownAfterClass();
    }

    public function testForceCreatesTable(): void
    {
        $command = new SetupDeduplicationCommand(self::$connection, self::TABLE);
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        // Introspect actual schema
        $schemaManager = self::$connection->createSchemaManager();
        $this->assertTrue($schemaManager->tablesExist([self::TABLE]));

        $columns = $schemaManager->listTableColumns(self::TABLE);
        $this->assertArrayHasKey('message_id', $columns);
        $this->assertSame(16, $columns['message_id']->getLength());
    }

    public function testForceIsIdempotent(): void
    {
        $command = new SetupDeduplicationCommand(self::$connection, self::TABLE);
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $tester->execute(['--force' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
```

## Acceptance Criteria

### Functional Requirements

- [x] `FunctionalDatabaseTestCase` base class with DBAL connection, schema setup, safety check
- [x] `tests/Functional/schema.sql` with deduplication table DDL
- [x] `DeduplicationDbalStoreTest` — insert, duplicate detection, data verification
- [x] `DeduplicationStoreCleanupTest` — old records deleted, recent records kept
- [x] `IdTypeRoundTripTest` — binary UUID v7 storage and retrieval
- [x] `SetupDeduplicationCommandTest` — `--force` creates table, idempotent, schema introspection

### Infrastructure Requirements

- [x] `Functional` testsuite added to `phpunit.xml.dist`
- [x] Existing CI matrix job updated to `--testsuite Unit`
- [x] New `functional-tests` CI job with MySQL service
- [x] All functional tests pass locally via `docker compose run --rm php vendor/bin/phpunit --testsuite Functional`
- [ ] All functional tests pass in CI

### Quality Gates

- [x] No test depends on execution order of other test classes
- [x] Each test class can run in isolation
- [x] `SetupDeduplicationCommand` test uses a unique table name (no collision)
- [x] Safety check prevents running against non-test databases

## Implementation Order

1. **Infrastructure first** — schema.sql, base class, phpunit.xml.dist
2. **DeduplicationDbalStoreTest** — highest value, core safety guarantee
3. **DeduplicationStoreCleanupTest** — exercises real SQL
4. **IdTypeRoundTripTest** — binary storage verification
5. **SetupDeduplicationCommandTest** — DDL validation
6. **CI workflow update** — add functional job, scope matrix to Unit

## References

- Issue: #30
- Critical pattern: `docs/solutions/patterns/critical-patterns.md`
- Learnings: `docs/solutions/test-failures/fresh-environment-schema-setup-20260131.md`
- Learnings: `docs/solutions/test-failures/doctrine-transaction-middleware-orm-configuration.md`
- Deleted functional tests: commit `6725adc`
