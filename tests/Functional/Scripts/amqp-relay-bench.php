<?php

declare(strict_types=1);

use Freyr\MessageBroker\Message;
use Freyr\MessageBroker\Outbox\OutboxProducer;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\Format;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\MySqlPlatform;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Transport\Amqp\AmqpRelay;
use PhpAmqpLib\Connection\AMQPStreamConnection;

require dirname(__DIR__, 3).'/vendor/autoload.php';

final class BenchEvent extends Message
{
    public static function create(string $key, int $i): self
    {
        return new self(key: $key, name: 'bench.event', payload: [
            'index' => $i,
            'order_id' => "order-{$i}",
            'total_cents' => $i * 100,
            'note' => 'a reasonably sized payload string to make this realistic',
        ]);
    }
}

$n = (int) ($argv[1] ?? 1000);

$pdo = new PDO(
    getenv('MYSQL_DSN') ?: throw new RuntimeException('MYSQL_DSN missing'),
    getenv('MYSQL_USER') ?: '',
    getenv('MYSQL_PASSWORD') ?: '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);
$platform = new MySqlPlatform();
foreach ($platform->schemaSql(Format::Json) as $ddl) {
    $pdo->exec($ddl);
}
$pdo->exec("DELETE FROM outbox_messages WHERE lane = 'bench'");

$amqp = new AMQPStreamConnection(
    host: getenv('AMQP_HOST') ?: 'rabbitmq',
    port: (int) (getenv('AMQP_PORT') ?: 5672),
    user: getenv('AMQP_USER') ?: 'guest',
    password: getenv('AMQP_PASSWORD') ?: 'guest',
    vhost: getenv('AMQP_VHOST') ?: '/',
);
$channel = $amqp->channel();
$channel->exchange_declare('mb_bench', 'topic', false, true, false);
$channel->queue_declare('mb_bench_q', false, true, false, false);
$channel->queue_bind('mb_bench_q', 'mb_bench', '#');
$channel->queue_purge('mb_bench_q');

$store = new OutboxStore($pdo, $platform);
$producer = new OutboxProducer($store, new JsonWireFormat(), lane: 'bench');

$run = static function (bool $confirms) use ($n, $pdo, $producer, $store, $amqp): array {
    $start = hrtime(true);
    for ($i = 0; $i < $n; ++$i) {
        $producer->produce(BenchEvent::create("key-{$i}", $i));
    }
    $produceMs = (hrtime(true) - $start) / 1e6;

    $channel = $amqp->channel(); // fresh channel per run so confirm mode is isolated
    $relay = new AmqpRelay(
        outbox: $store,
        amqp: $channel,
        publish: new AmqpPublishConfig(exchange: 'mb_bench', publisherConfirms: $confirms),
        contentType: JsonWireFormat::CONTENT_TYPE,
        lane: 'bench',
        batchSize: $n,
    );

    $start = hrtime(true);
    $published = $relay->drainOnce();
    $drainMs = (hrtime(true) - $start) / 1e6;

    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE lane = 'bench'")
        ->fetchColumn();
    $channel->close();

    return [$produceMs, $drainMs, $published, $remaining];
};

printf("benchmark: %d messages, single process, single exchange, payload ~150 bytes\n\n", $n);

foreach ([
    true => 'per-message confirms ',
    false => 'no confirms (fire+forget)',
] as $confirms => $label) {
    [$produceMs, $drainMs, $published, $remaining] = $run((bool) $confirms);
    printf(
        "%s  produce: %6.0f ms (%6.0f msg/s)   drain: %6.0f ms (%6.0f msg/s)   published=%d remaining=%d\n",
        $label,
        $produceMs,
        $n / $produceMs * 1000,
        $drainMs,
        $published / $drainMs * 1000,
        $published,
        $remaining,
    );
}

// verify broker actually has the messages (second run is fire-and-forget)
sleep(1);
[, $messageCount] = $channel->queue_declare('mb_bench_q', true);
printf("\nqueue depth on broker after both runs: %d (expected %d)\n", $messageCount, 2 * $n);

$channel->queue_delete('mb_bench_q');
$channel->exchange_delete('mb_bench');
$channel->close();
$amqp->close();
