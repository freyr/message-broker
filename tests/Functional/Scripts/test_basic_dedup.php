<?php

declare(strict_types=1);

/**
 * Quick test: Publish message → consume it → check if dedup entry exists.
 */

require __DIR__.'/../../../vendor/autoload.php';

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Tests\Functional\TestKernel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Worker;

$kernel = new TestKernel('test', true);
$kernel->boot();

$connection = $kernel->getContainer()
    ->get('doctrine.dbal.default_connection');

// Clean tables
$connection->executeStatement('DELETE FROM message_broker_deduplication');
echo "✓ Cleaned database\n";

// Publish a simple message
$messageId = Id::new()->__toString();
$amqpConn = new AMQPStreamConnection('mysql', 5672, 'guest', 'guest', '/');
$channel = $amqpConn->channel();
$channel->queue_declare('test_inbox', false, true, false, false);
$message = new AMQPMessage(json_encode([
    'id' => Id::new()->__toString(),
    'name' => 'test',
    'timestamp' => CarbonImmutable::now()->toIso8601String(),
]), [
    'content_type' => 'application/json',
    'application_headers' => new AMQPTable([
        'type' => 'test.event.sent',
        'X-Message-Stamp-Freyr\MessageBroker\Contracts\MessageIdStamp' => json_encode([[
            'messageId' => $messageId,
        ]]),
    ]),
]);
$channel->basic_publish($message, '', 'test_inbox');
$channel->close();
$amqpConn->close();
echo "✓ Published message with ID: {$messageId}\n";

// Consume it
$receiver = $kernel->getContainer()
    ->get('messenger.transport.amqp_test');
$bus = $kernel->getContainer()
    ->get('messenger.default_bus');
$eventDispatcher = new EventDispatcher();
$eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1, $kernel->getContainer()->get('logger')));
$worker = new Worker([
    'amqp_test' => $receiver,
], $bus, $eventDispatcher, $kernel->getContainer()
    ->get('logger'));
$worker->run();
echo "✓ Consumed message\n";

// Check if dedup entry exists
$messageIdBinary = hex2bin(str_replace('-', '', $messageId));
$count = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM message_broker_deduplication WHERE message_id = ?',
    [$messageIdBinary],
    ['binary']
);

echo "\nResult: Dedup entry exists? ".($count > 0 ? 'YES ✓' : 'NO ❌')."\n";
echo "Expected: YES (handler succeeded, transaction should commit)\n";

if ($count === 0) {
    echo "\n⚠️  PROBLEM: Dedup entry does NOT exist after successful processing!\n";
    echo "This means doctrine_transaction middleware is rolling back even on success.\n";
    exit(1);
}

echo "\n✅ SUCCESS: Dedup entry exists as expected!\n";
exit(0);
