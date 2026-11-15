<?php

declare(strict_types=1);

// Consumer throughput benchmark: fills a durable queue with persistent JSON
// messages, forks N AmqpConsumer processes (each with its own PostgreSQL and
// AMQP connection) and measures the time until every message is processed
// once. The handler sleeps for <handler-sleep-ms> inside the consumer
// transaction to simulate business work. Runs against the compose services:
// make bench-consumer ARGS="<messages> <workers> <prefetch> <handler-sleep-ms>".

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$total, $workers, $prefetch, $sleepMs] = [
    (int) ($argv[1] ?? 2000),
    (int) ($argv[2] ?? 4),
    (int) ($argv[3] ?? 32),
    (int) ($argv[4] ?? 0),
];
$queue = 'mb_consumer_bench_q';

$pdo = static fn (): PDO => new PDO(
    getenv('POSTGRES_DSN') ?: throw new RuntimeException('POSTGRES_DSN missing'),
    getenv('POSTGRES_USER') ?: '',
    getenv('POSTGRES_PASSWORD') ?: '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);
$amqp = static fn (): AMQPStreamConnection => new AMQPStreamConnection(
    host: getenv('AMQP_HOST') ?: 'rabbitmq',
    port: (int) (getenv('AMQP_PORT') ?: 5672),
    user: getenv('AMQP_USER') ?: 'guest',
    password: getenv('AMQP_PASSWORD') ?: 'guest',
    vhost: getenv('AMQP_VHOST') ?: '/',
);
$platform = new PostgreSqlPlatform();

$db = $pdo();
foreach ($platform->schemaSql() as $ddl) {
    $db->exec($ddl);
}
$db->exec('TRUNCATE message_deduplication');
$db->exec('TRUNCATE dead_letters');

$connection = $amqp();
$channel = $connection->channel();
$channel->exchange_declare('mb_consumer_bench', 'topic', false, true, false);
$channel->queue_declare($queue, false, true, false, false);
$channel->queue_bind($queue, 'mb_consumer_bench', '#');
$channel->queue_purge($queue);
$publisher = new AmqpMessagePublisher(
    $connection->channel(),
    new AmqpPublishConfig('mb_consumer_bench'),
    JsonWireFormat::CONTENT_TYPE,
);
$body = (string) json_encode([
    'orderId' => str_repeat('x', 36),
    'amount' => 12345,
    'currency' => 'EUR',
]);
for ($sent = 0; $sent < $total; $sent += 500) {
    $batch = [];
    for ($i = 0; $i < min(500, $total - $sent); ++$i) {
        $batch[] = new OutboxRecord(Uuid::v7()->toString(), 'bench', 'bench.event', $body, [], EpochMillis::now());
    }
    $publisher->publishBatch($batch);
}
// A forked child that exits closes inherited sockets: open nothing across the fork.
$connection->close();
$db = null;

$start = hrtime(true);
$children = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $workerPdo = $pdo();
        $consumer = new AmqpConsumer(
            channel: $amqp()->channel(),
            queue: new AmqpQueueConfig($queue, $prefetch),
            deserializer: new JsonDeserializer(),
            dispatcher: new CallableDispatcher(static function (IncomingMessage $message) use ($sleepMs): void {
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000); // simulated business work inside the consumer transaction
                }
            }),
            pdo: $workerPdo,
            deduplication: new PdoDeduplicationStore($workerPdo, $platform),
            retryPolicy: new AmqpRetryPolicy(),
            deadLetters: new PdoDeadLetterStore($workerPdo, $platform),
            name: 'bench',
        );
        $consumer->run(); // until SIGTERM
        exit(0);
    }
    $children[] = $pid;
}

$db = $pdo();
while ((int) $db->query('SELECT COUNT(*) FROM message_deduplication')->fetchColumn() < $total) {
    usleep(20_000);
}
$wallMs = (hrtime(true) - $start) / 1e6;
foreach ($children as $pid) {
    posix_kill($pid, SIGTERM);
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}

$processed = (int) $db->query('SELECT COUNT(*) FROM message_deduplication')->fetchColumn();
$ideal = $sleepMs > 0 ? $workers * 1000 / $sleepMs : 0;
printf(
    "workers=%2d prefetch=%3d handler=%2d ms  %6.0f msg/s  (sleep-only ceiling %5.0f msg/s)  wall=%6.0f ms  processed=%d of %d\n",
    $workers,
    $prefetch,
    $sleepMs,
    $total / $wallMs * 1000,
    $ideal,
    $wallMs,
    $processed,
    $total,
);
