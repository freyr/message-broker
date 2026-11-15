<?php

declare(strict_types=1);

// Wire format benchmark: JSON against Avro for one payload, through each
// stage of the library. Runs against the compose services:
// make bench-format ARGS="<json|avro> <messages> <small|order> [consumers]".
//   1. encode    WireFormat::encode() in a loop (CPU only)
//   2. decode    Deserializer::deserialize() in a loop (CPU only)
//   3. produce   OutboxProducer::produce(), 100 messages per transaction
//   4. relay     one AmqpRelay drains the lane (batch 500, confirms, persistent)
//   5. consume   N AmqpConsumer processes with an empty handler

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\DeadLetter\PdoDeadLetterStore;
use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Outbox\OutboxPartitionManager;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\PdoOutboxStore;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Deserializer;
use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Serializer\JsonDeserializer;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Serializer\WireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlPlatform;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Transport\Amqp\AmqpConsumer;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpQueueConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use Freyr\MessageBroker\Transport\Amqp\AmqpRetryPolicy;
use Freyr\MessageBroker\Transport\PdoDeduplicationStore;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Symfony\Component\Uid\Uuid;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

[$format, $total, $payloadKind, $consumers] = [
    $argv[1] ?? 'json',
    (int) ($argv[2] ?? 20000),
    $argv[3] ?? 'order',
    (int) ($argv[4] ?? 4),
];

final class BenchMessage extends Message
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $name, array $payload)
    {
        parent::__construct($name, $payload);
    }
}

$schemas = [
    'small' => ['order.placed', $root.'/tests/Fixtures/schemas/order.placed/v2.avsc'],
    'order' => ['bench.order', __DIR__.'/schemas/bench.order/v1.avsc'],
];
[$messageName, $schemaPath] = $schemas[$payloadKind] ?? throw new InvalidArgumentException("payload must be small or order, got {$payloadKind}");

/** @return array<string, mixed> */
$payload = static function (int $i) use ($payloadKind): array {
    if ($payloadKind === 'small') {
        return [
            'order_id' => Uuid::v7()->toString(),
            'total_cents' => 10_000 + $i,
            'currency' => 'EUR',
        ];
    }
    $items = [];
    for ($n = 1; $n <= 5; ++$n) {
        $items[] = [
            'sku' => sprintf('SKU-%05d-%d', $i % 50_000, $n),
            'name' => "Product number {$n} of the bench catalog",
            'quantity' => $n,
            'unit_price_cents' => 1_999 * $n,
        ];
    }

    return [
        'order_id' => Uuid::v7()->toString(),
        'customer_id' => Uuid::v7()->toString(),
        'status' => 'PLACED',
        'placed_at' => EpochMillis::now(),
        'currency' => 'EUR',
        'subtotal_cents' => 29_985,
        'tax_cents' => 6_896,
        'shipping_cents' => 499,
        'total_cents' => 37_380 + $i,
        'coupon_code' => $i % 3 === 0 ? 'WINTER-10' : null,
        'channel' => 'web',
        'shipping_address' => [
            'name' => 'Jane Example',
            'street' => 'Example Street 12/4',
            'city' => 'Warsaw',
            'postal_code' => '00-001',
            'country' => 'PL',
        ],
        'items' => $items,
        'tags' => ['first-order', 'newsletter'],
    ];
};

/** @return array{WireFormat, Deserializer} */
$codec = static function () use ($format, $messageName, $schemaPath): array {
    if ($format === 'json') {
        return [new JsonWireFormat(), new JsonDeserializer()];
    }
    $store = new FileSchemaStore([
        $messageName => $schemaPath,
    ]);
    $registry = new FileSchemaRegistry([$schemaPath]);

    return [new AvroWireFormat($store, $registry), new AvroDeserializer($registry, $store)];
};
[$wireFormat, $deserializer] = $codec();
$contentType = $wireFormat->contentType();

// 1. encode, 2. decode — CPU only, no IO.
$samples = array_map($payload, range(1, 1_000));
$t = hrtime(true);
$bodies = [];
for ($i = 0; $i < $total; ++$i) {
    $bodies[] = $wireFormat->encode($messageName, $samples[$i % 1_000]);
}
$encodeMs = (hrtime(true) - $t) / 1e6;
$avgBytes = array_sum(array_map(strlen(...), array_slice($bodies, 0, 1_000))) / 1_000;
$headers = MetadataHeader::envelope(Uuid::v7()->toString(), $messageName, EpochMillis::now());
$t = hrtime(true);
for ($i = 0; $i < $total; ++$i) {
    $deserializer->deserialize($bodies[$i], $headers);
}
$decodeMs = (hrtime(true) - $t) / 1e6;
unset($bodies);

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
foreach ($platform->schemaSql($format === 'avro' ? Format::Avro : Format::Json) as $ddl) {
    $db->exec($ddl);
}
new OutboxPartitionManager($db)->ensurePartitions(1);
$db->exec('TRUNCATE outbox_messages, message_deduplication, dead_letters');
$db->exec('VACUUM outbox_messages');

$queue = 'mb_format_bench_q';
$connection = $amqp();
$channel = $connection->channel();
$channel->exchange_declare('mb_format_bench', 'topic', false, true, false);
$channel->queue_declare($queue, false, true, false, false);
$channel->queue_bind($queue, 'mb_format_bench', '#');
$channel->queue_purge($queue);

// 3. produce — 100 messages per application transaction.
$producer = new OutboxProducer(new PdoOutboxStore($db, $platform), $wireFormat, 'bench');
$messages = array_map(static fn (int $i): BenchMessage => new BenchMessage($messageName, $samples[$i % 1_000]), range(0, $total - 1));
$t = hrtime(true);
foreach (array_chunk($messages, 100) as $chunk) {
    $db->beginTransaction();
    foreach ($chunk as $message) {
        $producer->produce($message);
    }
    $db->commit();
}
$produceMs = (hrtime(true) - $t) / 1e6;
unset($messages);
$tableBytes = (int) $db->query(
    "SELECT SUM(pg_total_relation_size(relid)) FROM pg_partition_tree('outbox_messages') WHERE isleaf",
)->fetchColumn();
$bodyBytes = (int) $db->query('SELECT SUM(octet_length(body)) FROM outbox_messages')->fetchColumn();

// 4. relay — one worker, the library defaults.
$relay = new AmqpRelay(new PdoOutboxStore($db, $platform), $connection->channel(), new AmqpPublishConfig('mb_format_bench'), $contentType, 'bench');
$t = hrtime(true);
while ($relay->drainOnce() > 0) {
}
$relayMs = (hrtime(true) - $t) / 1e6;
// A forked child that exits closes inherited sockets: open nothing across the fork.
$connection->close();
$db = null;

// 5. consume — N processes, empty handler, prefetch 32.
$t = hrtime(true);
$children = [];
for ($w = 0; $w < $consumers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        [, $workerDeserializer] = $codec();
        $workerPdo = $pdo();
        new AmqpConsumer(
            channel: $amqp()->channel(),
            queue: new AmqpQueueConfig($queue, 32),
            deserializer: $workerDeserializer,
            dispatcher: new CallableDispatcher(static function (): void {}),
            pdo: $workerPdo,
            deduplication: new PdoDeduplicationStore($workerPdo, $platform),
            retryPolicy: new AmqpRetryPolicy(),
            deadLetters: new PdoDeadLetterStore($workerPdo, $platform),
            name: 'bench',
        )->run(); // until SIGTERM
        exit(0);
    }
    $children[] = $pid;
}
$db = $pdo();
while ((int) $db->query('SELECT COUNT(*) FROM message_deduplication')->fetchColumn() < $total) {
    usleep(20_000);
}
$consumeMs = (hrtime(true) - $t) / 1e6;
foreach ($children as $pid) {
    posix_kill($pid, SIGTERM);
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}
$deadLetters = (int) $db->query('SELECT COUNT(*) FROM dead_letters')->fetchColumn();

$rate = static fn (float $ms): float => $total / $ms * 1000;
printf(
    "%-4s %-5s body=%5.0f B  encode=%7.0f/s  decode=%7.0f/s  produce=%6.0f msg/s  relay=%6.0f msg/s %5.1f MB/s  consume(x%d)=%5.0f msg/s  outbox=%5.1f MB (body %5.1f MB)  dlq=%d\n",
    $format,
    $payloadKind,
    $avgBytes,
    $rate($encodeMs),
    $rate($decodeMs),
    $rate($produceMs),
    $rate($relayMs),
    $bodyBytes / $relayMs / 1000,
    $consumers,
    $rate($consumeMs),
    $tableBytes / 1e6,
    $bodyBytes / 1e6,
    $deadLetters,
);
