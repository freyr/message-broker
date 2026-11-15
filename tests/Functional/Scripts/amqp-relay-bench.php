<?php

declare(strict_types=1);

// Relay throughput benchmark: fills the outbox, forks N relay workers (each
// with its own PostgreSQL and AMQP connection) and drains one lane with
// publisher confirms and persistent messages. Runs against the compose
// services: make bench ARGS="<rows> <workers> <batch> [mode]".
// mode: full (default) | db-only (no AMQP publish) | amqp-only (no database)

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Storage\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Transport\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Transport\Amqp\AmqpPublishConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

[$rows, $workers, $batch] = [(int) ($argv[1] ?? 50000), (int) ($argv[2] ?? 1), (int) ($argv[3] ?? 500)];
$mode = $argv[4] ?? 'full';

$pdo = static fn (): PDO => new PDO(getenv('POSTGRES_DSN'), getenv('POSTGRES_USER') ?: '', getenv('POSTGRES_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$amqp = static fn (): AMQPStreamConnection => new AMQPStreamConnection(getenv('AMQP_HOST') ?: 'rabbitmq', 5672, 'guest', 'guest');

$db = $pdo();
(new PostgreSqlSchemaInstaller($db))->install(1);
$db->exec('TRUNCATE outbox_messages');
$body = json_encode([
    'orderId' => str_repeat('x', 36),
    'amount' => 12345,
    'currency' => 'EUR',
    'items' => [[
        'sku' => 'A-1',
        'qty' => 2,
    ], [
        'sku' => 'B-7',
        'qty' => 1,
    ]],
]);
$stmt = $db->prepare(
    "INSERT INTO outbox_messages (id, lane, message_name, body, headers, created_at, available_at, attempts)
     SELECT gen_random_uuid(), 'bench', 'bench.event', convert_to(:body, 'UTF8'), '{}'::jsonb,
            (now() AT TIME ZONE 'UTC') - interval '1 second', (now() AT TIME ZONE 'UTC') - interval '1 second', 0
     FROM generate_series(1, :n)",
);
$stmt->execute([
    'body' => $body,
    'n' => $rows,
]);
$db->exec('ANALYZE outbox_messages');

$setup = $amqp();
$ch = $setup->channel();
$ch->exchange_declare('mb_bench', 'topic', false, true, false);
$ch->queue_declare('mb_bench_q', false, true, false, false);
$ch->queue_bind('mb_bench_q', 'mb_bench', '#');
$ch->queue_purge('mb_bench_q');
// A forked child that exits closes inherited sockets: open nothing across the fork.
$ch->close();
$setup->close();
$stmt = null;
$db = null;

$start = hrtime(true);
$children = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $wpdo = $pdo();
        $store = new PostgreSqlOutboxStore($wpdo);
        $conn = $mode === 'db-only' ? null : $amqp();
        $publisher = $conn === null ? null : new AmqpMessagePublisher($conn->channel(), new AmqpPublishConfig('mb_bench'), JsonWireFormat::CONTENT_TYPE);
        $publishNs = 0;
        $total = 0;
        $rounds = 0;
        if ($mode === 'amqp-only') {
            $record = new OutboxRecord('01900000-0000-7000-8000-000000000001', 'bench', 'bench.event', $body, [], 1_749_722_400_123);
            $share = intdiv($rows, $workers);
            for ($done = 0; $done < $share; $done += $batch) {
                $t = hrtime(true);
                $publisher->publishBatch(array_fill(0, min($batch, $share - $done), $record));
                $publishNs += hrtime(true) - $t;
                $total += min($batch, $share - $done);
                ++$rounds;
            }
        } else {
            while (true) {
                $n = $store->drainClaimed('bench', $batch, function (array $claimed) use ($publisher, &$publishNs): ClaimOutcome {
                    $t = hrtime(true);
                    $publisher?->publishBatch($claimed);
                    $publishNs += hrtime(true) - $t;

                    return ClaimOutcome::published(array_map(static fn (OutboxRecord $r): string => $r->id, $claimed));
                });
                $total += $n;
                ++$rounds;
                if ($n === 0 && (int) $wpdo->query('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL')->fetchColumn() === 0) {
                    break;
                }
            }
        }
        file_put_contents(sys_get_temp_dir()."/mb-bench-{$w}.json", json_encode([
            'rows' => $total,
            'rounds' => $rounds,
            'publish_ms' => $publishNs / 1e6,
        ]));
        exit(0);
    }
    $children[] = $pid;
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}
$wallMs = (hrtime(true) - $start) / 1e6;

$stats = array_map(static fn (int $w): array => json_decode(file_get_contents(sys_get_temp_dir()."/mb-bench-{$w}.json"), true), range(0, $workers - 1));
$published = array_sum(array_column($stats, 'rows'));
$publishMs = array_sum(array_column($stats, 'publish_ms')) / $workers;
$db = $pdo();
$ch = $amqp()->channel();
$remaining = (int) $db->query('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL')->fetchColumn();
usleep(300_000);
[, $queued] = $ch->queue_declare('mb_bench_q', true);

printf(
    "%-9s workers=%2d batch=%4d  %7.0f msg/s  wall=%6.0f ms  publish share=%3.0f%%  published=%d remaining=%d queued=%d\n",
    $mode,
    $workers,
    $batch,
    $published / $wallMs * 1000,
    $wallMs,
    $wallMs > 0 ? $publishMs / $wallMs * 100 : 0,
    $published,
    $remaining,
    $queued,
);
