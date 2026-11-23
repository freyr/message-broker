<?php

declare(strict_types=1);

// Outbox cleanup benchmark: the PostgreSQL cost of two ways to remove
// published rows, measured with the library's Relay and AmqpMessagePublisher.
//
//   mark         the library: the relay sets published_at (UPDATE); retention
//                drops the expired daily partition (PostgreSqlOutboxRetention,
//                DETACH ... CONCURRENTLY + DROP TABLE).
//   mark-vacuum  as mark, plus a VACUUM of the partition before the drop (what
//                autovacuum does during the day when it is on for the partition).
//   delete       the relay deletes each published row in the claim transaction
//                (PostgreSqlOutboxStore with PublishedRows::Delete); VACUUM removes the dead
//                rows; retention then drops the empty partition.
//
// Scenarios (make bench-outbox-cleanup ARGS="<scenario> [key=value ...]"):
//
//   drain   A backlog of <rows> in a past daily partition, drained by <workers>
//           relay workers (Relay::drainOnce until no row is left). Autovacuum is
//           off for the partition, so each cleanup is measured as its own phase.
//           Keys: rows=200000 workers=4 batch=500 payload=200 repeat=3
//                 modes=delete,mark,mark-vacuum publisher=amqp
//   steady  <producers> insert at <rate> rows/s in total (PostgreSqlOutboxStore
//           ::insert, one row per transaction, as an application does) while
//           <workers> relay workers run Relay::run for <seconds>. Autovacuum is
//           on with the server defaults. After the window: the cleanup.
//           The run also samples the wait events of the sessions (each 20 ms)
//           and the plan of the claim query (EXPLAIN, each second).
//           Keys: rate=2000 seconds=120 producers=4 workers=2 batch=500
//                 payload=200 repeat=1 modes=delete,mark publisher=amqp
//
// publisher: amqp (AmqpMessagePublisher with confirms, as in production) or
// null (no broker; the relay transaction is shorter).
//
// The measures, for each phase:
//   cpu    the CPU time of the whole PostgreSQL container (cgroup cpu.stat,
//          read with pg_read_file): backends, autovacuum, WAL writer,
//          checkpointer. Each phase ends with a CHECKPOINT inside the window,
//          so the writes of dirty pages count for the phase that made them.
//   wal    the WAL bytes of the phase.
//   wall   the duration of the phase.
// The benchmark uses its own database, outbox_bench, and recreates the outbox
// table for each run. Each run alternates the order of the modes.

require dirname(__DIR__).'/vendor/autoload.php';

use Freyr\MessageBroker\Adapter\Amqp\AmqpMessagePublisher;
use Freyr\MessageBroker\Adapter\Amqp\AmqpPublishConfig;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxRetention;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Adapter\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Adapter\PostgreSql\PublishedRows;
use Freyr\MessageBroker\Outbox\OutboxPublisher;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Outbox\Relay;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Worker\WorkerLimits;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Symfony\Component\Uid\Uuid;

/** A publisher with no broker: each row counts as published. */
final class NullPublisher implements OutboxPublisher
{
    public function publishBatch(array $batch): array
    {
        return [];
    }

    public function maxBatchSize(): int
    {
        return 1000;
    }
}

const BENCH_DB = 'outbox_bench';
const LANE = 'bench';
const EXCHANGE = 'mb_cleanup_bench';
const QUEUE = 'mb_cleanup_bench_q';

$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['drain', 'steady'], true)) {
    fwrite(STDERR, "Usage: outbox-cleanup-bench.php <drain|steady> [key=value ...]\n");
    exit(2);
}
$defaults = $scenario === 'drain'
    ? [
        'rows' => '200000',
        'workers' => '4',
        'batch' => '500',
        'payload' => '200',
        'repeat' => '3',
        'modes' => 'delete,mark,mark-vacuum',
        'publisher' => 'amqp',
    ]
    : [
        'rate' => '2000',
        'seconds' => '120',
        'producers' => '4',
        'workers' => '2',
        'batch' => '500',
        'payload' => '200',
        'repeat' => '1',
        'modes' => 'delete,mark',
        'publisher' => 'amqp',
    ];
$opt = $defaults;
foreach (array_slice($argv, 2) as $arg) {
    [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');
    if (!array_key_exists($key, $defaults)) {
        fwrite(STDERR, "Unknown key: {$key}\n");
        exit(2);
    }
    $opt[$key] = $value;
}
$modes = explode(',', $opt['modes']);
foreach ($modes as $mode) {
    if (!in_array($mode, ['delete', 'mark', 'mark-vacuum'], true)) {
        fwrite(STDERR, "Unknown mode: {$mode}\n");
        exit(2);
    }
}

function connect(?string $database = BENCH_DB): PDO
{
    $dsn = (string) getenv('POSTGRES_DSN');
    if ($database !== null) {
        $dsn = (string) preg_replace('/dbname=[^;]+/', 'dbname='.$database, $dsn);
    }

    return new PDO($dsn, getenv('POSTGRES_USER') ?: '', getenv('POSTGRES_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function amqp(): AMQPStreamConnection
{
    return new AMQPStreamConnection(getenv('AMQP_HOST') ?: 'rabbitmq', (int) (getenv('AMQP_PORT') ?: 5672), 'guest', 'guest');
}

function publisher(string $kind, ?AMQPStreamConnection $connection): OutboxPublisher
{
    return $kind === 'null' || $connection === null
        ? new NullPublisher()
        : new AmqpMessagePublisher($connection->channel(), new AmqpPublishConfig(EXCHANGE), JsonWireFormat::CONTENT_TYPE);
}

function store(string $mode, PDO $pdo): OutboxStore
{
    return new PostgreSqlOutboxStore($pdo, $mode === 'delete' ? PublishedRows::Delete : PublishedRows::Mark);
}

/** @return array{cpu: float, wal: int, t: float} cpu in ms, wal in bytes, t in ms */
function snapshot(PDO $pdo): array
{
    $stat = (string) $pdo->query("SELECT pg_read_file('/sys/fs/cgroup/cpu.stat')")->fetchColumn();
    preg_match('/^usage_usec (\d+)/m', $stat, $match);

    return [
        'cpu' => (int) $match[1] / 1000,
        'wal' => (int) $pdo->query("SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), '0/0')::bigint")->fetchColumn(),
        't' => hrtime(true) / 1e6,
    ];
}

/**
 * Runs $work, then a CHECKPOINT, and returns the cost of both.
 *
 * @return array{cpu: float, wal: int, wall: float}
 */
function phase(PDO $pdo, callable $work): array
{
    $before = snapshot($pdo);
    $work();
    $pdo->exec('CHECKPOINT');
    $after = snapshot($pdo);

    return [
        'cpu' => $after['cpu'] - $before['cpu'],
        'wal' => $after['wal'] - $before['wal'],
        'wall' => $after['t'] - $before['t'],
    ];
}

function body(int $bytes): string
{
    $prefix = '{"orderId":"0190a3c2-7d1e-7000-8000-000000000001","amount":12345,"currency":"EUR","note":"';

    return $prefix.str_repeat('x', max(0, $bytes - strlen($prefix) - 2)).'"}';
}

function prepareDatabase(): void
{
    $admin = connect(null);
    if ($admin->query("SELECT 1 FROM pg_database WHERE datname = '".BENCH_DB."'")->fetchColumn() === false) {
        $admin->exec('CREATE DATABASE '.BENCH_DB);
    }
}

function freshOutbox(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS outbox_messages CASCADE');
    $pdo->exec("DO \$\$ DECLARE t text; BEGIN FOR t IN SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^outbox_messages_p[0-9]{8}$' LOOP EXECUTE format('DROP TABLE %I', t); END LOOP; END \$\$");
    (new PostgreSqlSchemaInstaller($pdo))->install(1);
}

function purgeQueue(string $publisherKind): void
{
    if ($publisherKind !== 'amqp') {
        return;
    }
    $connection = amqp();
    $channel = $connection->channel();
    $channel->exchange_declare(EXCHANGE, 'topic', false, true, false);
    $channel->queue_declare(QUEUE, false, true, false, false);
    $channel->queue_bind(QUEUE, EXCHANGE, '#');
    $channel->queue_purge(QUEUE);
    $channel->close();
    $connection->close();
}

/** @return array{rows: int, size: int, heap: int, indexes: int, dead: int, live: int, autovacuum: int} */
function tableStats(PDO $pdo, string $table): array
{
    $pdo->exec('SELECT pg_stat_clear_snapshot()');
    $row = $pdo->query(
        "SELECT pg_total_relation_size('{$table}') AS size, pg_relation_size('{$table}') AS heap, pg_indexes_size('{$table}') AS indexes,
                COALESCE(s.n_dead_tup, 0) AS dead, COALESCE(s.n_live_tup, 0) AS live, COALESCE(s.autovacuum_count, 0) AS autovacuum,
                COALESCE(s.seq_scan, 0) AS seq_scan, COALESCE(s.seq_tup_read, 0) AS seq_tup_read,
                COALESCE(io.heap_blks_hit, 0) + COALESCE(io.heap_blks_read, 0) AS heap_blocks,
                COALESCE(io.idx_blks_hit, 0) + COALESCE(io.idx_blks_read, 0) AS index_blocks,
                (SELECT COALESCE(SUM(i.idx_scan), 0) FROM pg_stat_user_indexes i WHERE i.relid = c.oid AND i.indexrelname NOT LIKE '%pkey') AS pending_idx_scan,
                (SELECT COALESCE(SUM(i.idx_tup_read), 0) FROM pg_stat_user_indexes i WHERE i.relid = c.oid AND i.indexrelname NOT LIKE '%pkey') AS pending_idx_tup_read,
                (SELECT COALESCE(SUM(i.idx_tup_read), 0) FROM pg_stat_user_indexes i WHERE i.relid = c.oid AND i.indexrelname LIKE '%pkey') AS pkey_idx_tup_read,
                (SELECT COUNT(*) FROM \"{$table}\") AS rows
           FROM pg_class c LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid LEFT JOIN pg_statio_user_tables io ON io.relid = c.oid
          WHERE c.relname = '{$table}'",
    )->fetch(PDO::FETCH_ASSOC);

    return array_map('intval', $row);
}

/**
 * Forks one process for each callable and waits for all of them.
 *
 * @param list<callable(int): void> $jobs
 */
function forkAll(array $jobs): void
{
    $children = [];
    foreach ($jobs as $index => $job) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // The child inherits the connection of the parent: a normal exit
            // closes it and ends the session of the parent. The connections of
            // the job close when the job returns; then the child replaces its
            // process image, so no destructor runs on the inherited socket.
            $ok = true;
            try {
                $job($index);
            } catch (Throwable $error) {
                fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
                $ok = false;
            }
            pcntl_exec($ok ? '/bin/true' : '/bin/false');
            exit(1);
        }
        $children[] = $pid;
    }
    $failed = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $failed += pcntl_wexitstatus($status) === 0 ? 0 : 1;
    }
    if ($failed > 0) {
        throw new RuntimeException("{$failed} child process(es) failed");
    }
}

/** @return array<string, mixed> */
function runDrain(string $mode, array $opt): array
{
    $pdo = connect();
    freshOutbox($pdo);
    $day = new DateTimeImmutable('today -3 day', new DateTimeZone('UTC'));
    $retention = new PostgreSqlOutboxRetention($pdo);
    [$partition] = $retention->ensureCapacity(0, $day);
    $insert = $pdo->prepare(
        "INSERT INTO outbox_messages (id, lane, message_name, body, headers, created_at, available_at, attempts)
         SELECT gen_random_uuid(), :lane, 'bench.event', convert_to(:body, 'UTF8'), '{}'::jsonb,
                :day::timestamp + interval '12 hours' + g * interval '1 millisecond', (now() AT TIME ZONE 'UTC') - interval '1 second', 0
         FROM generate_series(1, :n) AS g",
    );
    $insert->execute([
        'lane' => LANE,
        'body' => body((int) $opt['payload']),
        'day' => $day->format('Y-m-d'),
        'n' => (int) $opt['rows'],
    ]);
    $pdo->exec("ALTER TABLE \"{$partition}\" SET (autovacuum_enabled = false, toast.autovacuum_enabled = false)");
    $pdo->exec("VACUUM (ANALYZE) \"{$partition}\""); // a clean start: visibility map set, statistics current
    $pdo->exec('CHECKPOINT');
    purgeQueue($opt['publisher']);

    $workers = (int) $opt['workers'];
    $relay = phase($pdo, static function () use ($mode, $opt, $workers): void {
        forkAll(array_fill(0, $workers, static function () use ($mode, $opt): void {
            $wpdo = connect();
            $connection = $opt['publisher'] === 'amqp' ? amqp() : null;
            $relay = new Relay(store($mode, $wpdo), publisher($opt['publisher'], $connection), LANE, (int) $opt['batch']);
            $left = $wpdo->prepare('SELECT EXISTS (SELECT 1 FROM outbox_messages WHERE published_at IS NULL AND abandoned_at IS NULL)');
            while (true) {
                if ($relay->drainOnce() === 0) {
                    $left->execute();
                    if ($left->fetchColumn() === false) {
                        break;
                    }
                }
            }
        }));
    });
    $afterRelay = tableStats($pdo, $partition);

    $vacuum = [
        'cpu' => 0.0,
        'wal' => 0,
        'wall' => 0.0,
    ];
    if ($mode !== 'mark') {
        $vacuum = phase($pdo, static fn () => $pdo->exec("VACUUM \"{$partition}\""));
    }
    $afterVacuum = tableStats($pdo, $partition);
    $purge = phase($pdo, static function () use ($retention): void {
        $report = $retention->purge(1);
        if (count($report->dropped) !== 1) {
            throw new RuntimeException('The purge dropped '.count($report->dropped).' partitions, expected 1');
        }
    });

    return [
        'relay' => $relay,
        'vacuum' => $vacuum,
        'purge' => $purge,
        'after_relay' => $afterRelay,
        'after_vacuum' => $afterVacuum,
    ];
}

/** @return array<string, mixed> */
function runSteady(string $mode, array $opt): array
{
    $pdo = connect();
    freshOutbox($pdo);
    $retention = new PostgreSqlOutboxRetention($pdo);
    $partition = 'outbox_messages_p'.gmdate('Ymd');
    $pdo->exec('CHECKPOINT');
    purgeQueue($opt['publisher']);

    $seconds = (int) $opt['seconds'];
    $producers = (int) $opt['producers'];
    $perProducer = (float) $opt['rate'] / $producers;
    $body = body((int) $opt['payload']);
    $jobs = [];
    for ($p = 0; $p < $producers; ++$p) {
        $jobs[] = static function () use ($seconds, $perProducer, $body): void {
            $store = new PostgreSqlOutboxStore(connect());
            $start = hrtime(true) / 1e9;
            for ($k = 0; ; ++$k) {
                $due = $start + $k / $perProducer;
                $now = hrtime(true) / 1e9;
                if ($now - $start >= $seconds) {
                    break;
                }
                if ($due > $now) {
                    usleep((int) (($due - $now) * 1e6));
                }
                $store->insert(new OutboxRecord(Uuid::v7()->toString(), LANE, 'bench.event', $body, [], EpochMillis::now()));
            }
        };
    }
    for ($w = 0; $w < (int) $opt['workers']; ++$w) {
        $jobs[] = static function () use ($mode, $opt, $seconds): void {
            $connection = $opt['publisher'] === 'amqp' ? amqp() : null;
            $relay = new Relay(store($mode, connect()), publisher($opt['publisher'], $connection), LANE, (int) $opt['batch']);
            $relay->run(new WorkerLimits(maxSeconds: $seconds + 2));
        };
    }
    // Samples of the wait events of the active sessions, each 20 ms, in its own process.
    $waitFile = sys_get_temp_dir().'/outbox-cleanup-bench-waits.json';
    $jobs[] = static function () use ($seconds, $waitFile): void {
        $spy = connect();
        $sample = $spy->prepare("SELECT COALESCE(wait_event_type || ':' || wait_event, 'CPU') FROM pg_stat_activity WHERE state = 'active' AND backend_type = 'client backend' AND pid <> pg_backend_pid() AND datname = current_database()");
        // The plan of the claim query (EXPLAIN, not executed), each second.
        $explain = $spy->prepare("EXPLAIN SELECT id FROM outbox_messages WHERE lane = 'bench' AND published_at IS NULL AND abandoned_at IS NULL AND available_at <= now() AT TIME ZONE 'UTC' ORDER BY available_at LIMIT 500 FOR UPDATE SKIP LOCKED");
        $counts = [];
        $plans = [];
        $samples = 0;
        $end = hrtime(true) / 1e9 + $seconds;
        while (hrtime(true) / 1e9 < $end) {
            $sample->execute();
            foreach ($sample->fetchAll(PDO::FETCH_COLUMN) as $event) {
                $counts[$event] = ($counts[$event] ?? 0) + 1;
            }
            if ($samples % 50 === 0) {
                $explain->execute();
                preg_match_all('/(Seq Scan|Index Scan|Index Only Scan|Bitmap Heap Scan|Bitmap Index Scan|Sort)/', implode("\n", $explain->fetchAll(PDO::FETCH_COLUMN)), $nodes);
                $shape = implode(' > ', $nodes[1]);
                $plans[$shape] = ($plans[$shape] ?? 0) + 1;
            }
            ++$samples;
            usleep(20_000);
        }
        arsort($counts);
        file_put_contents($waitFile, json_encode([
            'samples' => $samples,
            'plans' => $plans,
        ] + $counts));
    };
    $window = phase($pdo, static fn () => forkAll($jobs));
    $afterWindow = tableStats($pdo, $partition);
    $afterWindow['waits'] = json_decode((string) file_get_contents($waitFile), true);
    $pending = (int) $pdo->query('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL')->fetchColumn();

    $vacuum = [
        'cpu' => 0.0,
        'wal' => 0,
        'wall' => 0.0,
    ];
    if ($mode !== 'mark') {
        $vacuum = phase($pdo, static fn () => $pdo->exec("VACUUM \"{$partition}\""));
    }
    $purge = phase($pdo, static function () use ($retention): void {
        $report = $retention->purge(1, dropUnpublished: true, today: new DateTimeImmutable('today +3 day', new DateTimeZone('UTC')));
        if (count($report->dropped) < 1) {
            throw new RuntimeException('The purge dropped no partition');
        }
    });

    return [
        'relay' => $window,
        'vacuum' => $vacuum,
        'purge' => $purge,
        'after_relay' => $afterWindow,
        'pending' => $pending,
    ];
}

function median(array $values): float
{
    sort($values);
    $n = count($values);

    return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

prepareDatabase();
$idle = (static function (): float {
    $pdo = connect();
    $a = snapshot($pdo);
    sleep(5);
    $b = snapshot($pdo);

    return ($b['cpu'] - $a['cpu']) / (($b['t'] - $a['t']) / 1000);
})();
printf("scenario=%s %s\n", $scenario, implode(' ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($opt), $opt)));
printf("idle CPU of the PostgreSQL container: %.1f ms/s\n\n", $idle);

$results = [];
for ($r = 0; $r < (int) $opt['repeat']; ++$r) {
    $order = $r % 2 === 0 ? $modes : array_reverse($modes);
    foreach ($order as $mode) {
        $result = $scenario === 'drain' ? runDrain($mode, $opt) : runSteady($mode, $opt);
        $results[$mode][] = $result;
        echo json_encode([
            'run' => $r + 1,
            'mode' => $mode,
        ] + $result), "\n";
    }
}

$rows = $scenario === 'drain' ? (int) $opt['rows'] : null;
echo "\nMedians (cpu and wall in ms, WAL in MB):\n";
printf("%-12s %9s %9s %9s %9s %8s %8s %8s %9s %7s\n", 'mode', 'relay cpu', 'vac cpu', 'purge cpu', 'total cpu', 'WAL', 'wall', 'heap MB', 'idx MB', 'dead');
foreach ($modes as $mode) {
    $runs = $results[$mode];
    $m = static fn (string $phase, string $key): float => median(array_map(static fn (array $run): float => (float) $run[$phase][$key], $runs));
    $total = median(array_map(static fn (array $run): float => $run['relay']['cpu'] + $run['vacuum']['cpu'] + $run['purge']['cpu'], $runs));
    $wal = median(array_map(static fn (array $run): float => $run['relay']['wal'] + $run['vacuum']['wal'] + $run['purge']['wal'], $runs));
    printf(
        "%-12s %9.0f %9.0f %9.0f %9.0f %8.1f %8.0f %8.1f %9.1f %7.0f\n",
        $mode,
        $m('relay', 'cpu'),
        $m('vacuum', 'cpu'),
        $m('purge', 'cpu'),
        $total,
        $wal / 1048576,
        $m('relay', 'wall'),
        $m('after_relay', 'heap') / 1048576,
        $m('after_relay', 'indexes') / 1048576,
        $m('after_relay', 'dead'),
    );
}
if ($rows !== null) {
    echo "\nTotal PostgreSQL CPU for each published row (µs): ";
    foreach ($modes as $mode) {
        $total = median(array_map(static fn (array $run): float => $run['relay']['cpu'] + $run['vacuum']['cpu'] + $run['purge']['cpu'], $results[$mode]));
        printf('%s %.1f  ', $mode, $total * 1000 / $rows);
    }
    echo "\n";
}
