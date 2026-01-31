<?php

declare(strict_types=1);

/**
 * Demonstrates the transaction rollback problem with deduplication.
 *
 * This script simulates what happens during message consumption:
 * 1. Deduplication check (INSERT into table)
 * 2. Handler execution (throws exception)
 * 3. Transaction behavior (commit or rollback)
 */

require __DIR__.'/../../../vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Freyr\MessageBroker\Tests\Functional\TestKernel;

// Boot test kernel to get configured connection
$kernel = new TestKernel('test', true);
$kernel->boot();

$connection = $kernel->getContainer()
    ->get('doctrine.dbal.default_connection');
assert($connection instanceof Connection);

$messageId = '01234567-89ab-cdef-0123-456789abcdef';
$messageIdBinary = hex2bin(str_replace('-', '', $messageId));

echo "=== Transaction Rollback Demonstration ===\n\n";

// Clean up
$connection->executeStatement('DELETE FROM message_broker_deduplication');
echo "✓ Cleaned deduplication table\n\n";

// ===================================================================
// Scenario 1: WITHOUT TRANSACTION (Current behavior - BROKEN)
// ===================================================================
echo "Scenario 1: WITHOUT TRANSACTION\n";
echo "--------------------------------\n";

try {
    // Simulate deduplication check (auto-commit)
    $connection->insert('message_broker_deduplication', [
        'message_id' => $messageIdBinary,
        'message_name' => 'TestEvent',
        'processed_at' => new DateTimeImmutable(),
    ], [
        'message_id' => 'binary',
        'processed_at' => 'datetime_immutable',
    ]);
    echo "1. Deduplication entry inserted (auto-committed)\n";

    // Simulate handler throwing exception
    echo "2. Handler throws exception\n";
    throw new RuntimeException('Handler failed!');
} catch (RuntimeException $e) {
    echo "3. Exception caught: {$e->getMessage()}\n";
}

// Check if entry exists
$count = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM message_broker_deduplication WHERE message_id = ?',
    [$messageIdBinary],
    ['binary']
);

echo '4. Deduplication entry exists? '.($count > 0 ? 'YES ❌' : 'NO ✓')."\n";
echo "   → Result: Deduplication entry was NOT rolled back\n";
echo "   → Impact: Message will be treated as DUPLICATE on retry\n";
echo "   → Outcome: HANDLER WILL NEVER RUN AGAIN = DATA LOSS\n\n";

// Clean up for next scenario
$connection->executeStatement('DELETE FROM message_broker_deduplication');

// ===================================================================
// Scenario 2: WITH TRANSACTION (Expected behavior - CORRECT)
// ===================================================================
echo "Scenario 2: WITH TRANSACTION\n";
echo "-----------------------------\n";

$connection->beginTransaction();
echo "1. Transaction started\n";

try {
    // Simulate deduplication check (within transaction)
    $connection->insert('message_broker_deduplication', [
        'message_id' => $messageIdBinary,
        'message_name' => 'TestEvent',
        'processed_at' => new DateTimeImmutable(),
    ], [
        'message_id' => 'binary',
        'processed_at' => 'datetime_immutable',
    ]);
    echo "2. Deduplication entry inserted (within transaction)\n";

    // Simulate handler throwing exception
    echo "3. Handler throws exception\n";
    throw new RuntimeException('Handler failed!');
    $connection->commit();
} catch (RuntimeException $e) {
    echo "4. Exception caught: {$e->getMessage()}\n";
    $connection->rollBack();
    echo "5. Transaction rolled back\n";
}

// Check if entry exists
$count = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM message_broker_deduplication WHERE message_id = ?',
    [$messageIdBinary],
    ['binary']
);

echo '6. Deduplication entry exists? '.($count > 0 ? 'YES ❌' : 'NO ✓')."\n";
echo "   → Result: Deduplication entry WAS rolled back\n";
echo "   → Impact: Message can be retried\n";
echo "   → Outcome: HANDLER WILL RUN AGAIN = EVENTUAL SUCCESS\n\n";

echo "=== Summary ===\n";
echo "WITHOUT doctrine_transaction middleware: Deduplication entries commit even on failure\n";
echo "WITH doctrine_transaction middleware: Deduplication entries rollback on failure\n";
echo "\nThis is why doctrine_transaction middleware is CRITICAL for data integrity.\n";
