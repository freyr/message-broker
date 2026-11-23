<?php

declare(strict_types=1);

// Webhook flow control benchmark: fills webhook_deliveries with a backlog,
// forks N delivery workers (DeliveryLoop with the PostgreSQL delivery store
// and circuit breaker, the chosen rate limit store and HttpDeliverySender)
// against the test HTTP server (tools/webhook-test-server/server.mjs), and
// reads the request log of the server. Runs against the compose services:
//
//   make bench-webhook ARGS="<store> <lanes> <workers> <rps> <burst> <lane-rps> <batch> <endpoint-ms> <seconds>"
//
// store: postgresql | redis. lane-rps: the default lane rate (burst: one
// tenth, minimum 1); 0 = no lane limit. endpoint-ms: the answer delay of the
// endpoint. Each worker runs for <seconds>.
//
// The output:
//  - the most requests in a sliding window of 10 s (step 1 s) for all lanes
//    and for any lane, with the bounds (10 x rate + burst) x 1.01;
//  - for each steady window (10 s windows that start 10 s or more after the
//    first request and end 10 s or more before the last request): the
//    requests, the delivered rate and the limited grants (each lane grant
//    below its wanted count, also 0);
//  - D: the longest time from the end of an acquire() to the first request of
//    its pass in the server log;
//  - the acquire() duration (p50, p99) and, for postgresql, the share of the
//    samples of pg_stat_activity (each 10 ms) with a session that waits for a
//    lock on webhook_rate_limit;
//  - "limit active" (each steady window has a limited grant, a bucket-cut call
//    and 98% or more of 10 x rps requests) and, with a lane limit, "lanes
//    limited" (each steady window has a bucket-cut call, and each lane
//    has a limited grant in each steady window).
// The last line is one JSON object with the same values (prefix RESULT).

require dirname(__DIR__).'/vendor/autoload.php';

use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimiter;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlCircuitBreaker;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliverySchemaInstaller;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlDeliveryStore;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlRateLimiter;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;
use Freyr\MessageBroker\Testing\InMemorySubscriptionProvider;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Webhook\DeliverySender;
use Freyr\MessageBroker\Webhook\Http\HttpDeliverySender;
use Freyr\MessageBroker\Webhook\Http\HttpSenderConfig;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;
use Freyr\MessageBroker\Webhook\Subscription;
use Freyr\MessageBroker\Worker\WorkerLimits;

ini_set('memory_limit', '2G');

/**
 * Records each acquire(): the end time (the time of the grant, epoch ms), the
 * duration, the lanes whose grant was below the wanted count, and the sums.
 */
final class RecordingRateLimiter implements RateLimiter
{
    /** @var list<array{float, float, list<string>, int, int, int}> end ms, duration ms, limited lanes, wanted sum, grant sum, total */
    public array $acquires = [];

    public int $giveBacks = 0;

    public float $lastGrantMs = 0.0;

    public function __construct(
        private readonly RateLimiter $inner,
    ) {}

    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        $start = microtime(true) * 1000;
        $grants = $this->inner->acquire($wanted, $total, $limits);
        $end = microtime(true) * 1000;
        $limited = [];
        foreach ($wanted as $lane => $count) {
            if ($grants[$lane] < $count) {
                $limited[] = (string) $lane;
            }
        }
        $this->acquires[] = [$end, $end - $start, $limited, array_sum($wanted), array_sum($grants), $total];
        $this->lastGrantMs = $end;

        return $grants;
    }

    public function giveBack(array $unused, RateLimits $limits): void
    {
        ++$this->giveBacks;
        $this->inner->giveBack($unused, $limits);
    }
}

/** Records the grant time of the pass and the message IDs of each send() call. */
final class RecordingSender implements DeliverySender
{
    /** @var list<array{float, list<string>}> grant end ms of the pass, message IDs */
    public array $passes = [];

    public function __construct(
        private readonly DeliverySender $inner,
        private readonly RecordingRateLimiter $limiter,
    ) {}

    public function send(array $pairs): array
    {
        $ids = [];
        foreach ($pairs as $pair) {
            $ids[] = $pair->claimed->delivery->messageId;
        }
        $this->passes[] = [$this->limiter->lastGrantMs, $ids];

        return $this->inner->send($pairs);
    }

    public function maxSendMs(): int
    {
        return $this->inner->maxSendMs();
    }
}

/** The counts of one steady window of 10 s. */
final class SteadyWindow
{
    public int $requests = 0;

    /** the lane grants below the wanted count */
    public int $limited = 0;

    /** the acquire() calls whose grant sum was below min(total, wanted sum): a bucket cut them, not the batch size */
    public int $bucketCut = 0;

    /** @var array<string, true> the lanes with a limited grant */
    public array $limitedLanes = [];

    public function __construct(
        public readonly float $fromS,
    ) {}
}

/** @param list<float> $sorted */
function percentile(array $sorted, float $p): ?float
{
    if ($sorted === []) {
        return null;
    }

    return $sorted[min(count($sorted) - 1, (int) floor(count($sorted) * $p))];
}

function ms(?float $value): string
{
    return $value === null ? 'n/a' : sprintf('%.1f ms', $value);
}

function usage(): never
{
    fwrite(STDERR, "Usage: webhook-bench.php <postgresql|redis> <lanes> <workers> <rps> <burst> <lane-rps> <batch> <endpoint-ms> <seconds>\n");
    exit(2);
}

if (count($argv) !== 10 || !in_array($argv[1], ['postgresql', 'redis'], true)) {
    usage();
}
$store = $argv[1];
[$lanes, $workers] = [(int) $argv[2], (int) $argv[3]];
[$rps, $burst, $laneRps] = [(float) $argv[4], (int) $argv[5], (float) $argv[6]];
[$batch, $endpointMs, $seconds] = [(int) $argv[7], (int) $argv[8], (int) $argv[9]];
if ($lanes < 1 || $workers < 1 || !($rps > 0) || is_infinite($rps) || $burst < 1 || $laneRps < 0 || is_infinite($laneRps) || $batch < 1 || $endpointMs < 0 || $seconds < 1) {
    usage();
}

$globalRate = new Rate($rps, $burst);
$laneRate = $laneRps > 0 ? new Rate($laneRps) : null;
$limitForEachLane = 25;
$serverUrl = getenv('WEBHOOK_SERVER_URL') ?: 'http://webhook-server:8080';
$runId = sprintf('%d-%d', time(), getmypid());
$prefix = "/bench-{$runId}/";
$laneIds = array_map(static fn (int $i): string => sprintf('lane-%02d', $i), range(1, $lanes));
$backlog = max((int) ceil($rps * $seconds * 1.25), $lanes * 100);
$tmp = sys_get_temp_dir()."/mb-webhook-bench-{$runId}";

$pdo = static fn (): PDO => new PDO((string) getenv('POSTGRES_DSN'), getenv('POSTGRES_USER') ?: '', getenv('POSTGRES_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

printf(
    "webhook bench: store=%s lanes=%d workers=%d rps=%s burst=%d lane-rps=%s lane-burst=%s batch=%d lane-row-limit=%d endpoint-ms=%d seconds=%d\n",
    $store,
    $lanes,
    $workers,
    (string) $rps,
    $burst,
    (string) $laneRps,
    $laneRate === null ? '-' : (string) $laneRate->burst,
    $batch,
    $limitForEachLane,
    $endpointMs,
    $seconds,
);

// The test server must have the short log of this tool; a container that started before a change of
// server.mjs runs the old code.
$probe = @file_get_contents("{$serverUrl}/_times?prefix=/none");
if ($probe === false || !str_starts_with(trim($probe), '[')) {
    fwrite(STDERR, "The test server at {$serverUrl} does not answer GET /_times. Restart it: docker compose restart webhook-server\n");
    exit(1);
}

// Set-up: the tables, an empty state, and the backlog (due now, spread over the lanes).
$db = $pdo();
(new PostgreSqlSchemaInstaller($db))->install(1);
(new PostgreSqlDeliverySchemaInstaller($db))->install(1);
$db->exec('TRUNCATE webhook_deliveries, webhook_rate_limit, webhook_lanes');
$fill = $db->prepare(<<<'SQL'
    INSERT INTO webhook_deliveries (id, message_id, lane, message_name, body, headers, created_at, available_at, attempts)
    SELECT gen_random_uuid(), gen_random_uuid(), 'lane-' || lpad(CAST(1 + i % :lanes AS TEXT), 2, '0'), 'bench.event',
           convert_to('{"orderId":"0190a5f2-7c1e-7000-8000-000000000001","amount":12345,"currency":"EUR"}', 'UTF8'),
           CAST('{}' AS JSONB), now() AT TIME ZONE 'UTC', (now() AT TIME ZONE 'UTC') - INTERVAL '1 second', 0
    FROM generate_series(0, :n - 1) AS i
    SQL);
$fill->execute([
    'lanes' => $lanes,
    'n' => $backlog,
]);
$db->exec('ANALYZE webhook_deliveries');
$fill = null;
$db = null; // a forked child that exits closes inherited sockets: open nothing across the fork

$subscriptions = new InMemorySubscriptionProvider();
foreach ($laneIds as $lane) {
    $subscriptions->add(new Subscription($lane, "{$serverUrl}{$prefix}{$lane}?delay={$endpointMs}", ['whsec_'.base64_encode(random_bytes(24))]));
}
$config = new DeliveryLoopConfig(
    batchSize: $batch,
    limitForEachLane: $limitForEachLane,
    laneRefreshMs: 3_600_000,
    globalRate: $globalRate,
    defaultLaneRate: $laneRate,
);
$redisPrefix = "bench-{$runId}";

$start = microtime(true);
$children = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $connection = $pdo();
            if ($store === 'redis') {
                $redis = new Redis();
                $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 2.0);
                $inner = new RedisRateLimiter(new PhpRedisScriptRunner($redis), new RedisRateLimitConfig($redisPrefix));
            } else {
                // The rate limiter has its own connection, as the delivery store and the breaker share one.
                $inner = new PostgreSqlRateLimiter($pdo());
            }
            $limiter = new RecordingRateLimiter($inner);
            $sender = new RecordingSender(new HttpDeliverySender(new HttpSenderConfig(
                maxHostConnections: max($batch, $limitForEachLane),
                blockPrivateNetworks: false,
                allowHttp: true,
            )), $limiter);
            $loop = new DeliveryLoop(
                new PostgreSqlDeliveryStore($connection),
                $sender,
                $subscriptions,
                config: $config,
                rateLimiter: $limiter,
                circuitBreaker: new PostgreSqlCircuitBreaker($connection),
            );
            $loop->run(new WorkerLimits(maxSeconds: $seconds));
            file_put_contents("{$tmp}-w{$w}.json", json_encode([
                'acquires' => $limiter->acquires,
                'give_backs' => $limiter->giveBacks,
                'passes' => $sender->passes,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, "worker {$w}: ".$e::class.': '.$e->getMessage()."\n");
            exit(1);
        }
    }
    $children[] = $pid;
}

// The sampler of pg_stat_activity: each 10 ms, a session (not the sampler) that waits for a lock in a
// statement on webhook_rate_limit.
$sampler = null;
if ($store === 'postgresql') {
    $sampler = pcntl_fork();
    if ($sampler === 0) {
        $connection = $pdo();
        $query = $connection->prepare(<<<'SQL'
            SELECT COUNT(*) FROM pg_stat_activity
            WHERE wait_event_type = 'Lock' AND query LIKE '%webhook_rate_limit%' AND pid <> pg_backend_pid()
            SQL);
        [$samples, $waiting] = [0, 0];
        $deadline = $start + $seconds;
        $next = microtime(true);
        while ($next < $deadline) {
            $query->execute();
            ++$samples;
            if ((int) $query->fetchColumn() > 0) {
                ++$waiting;
            }
            $next += 0.01;
            $sleep = $next - microtime(true);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }
        file_put_contents("{$tmp}-sampler.json", json_encode([
            'samples' => $samples,
            'waiting' => $waiting,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }
}

$failed = 0;
foreach ($children as $pid) {
    $status = 0;
    pcntl_waitpid($pid, $status);
    /** @var int $status */
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        ++$failed;
    }
}
if ($sampler !== null) {
    pcntl_waitpid($sampler, $status);
}
if ($failed > 0) {
    fwrite(STDERR, "{$failed} worker(s) failed\n");
    exit(1);
}

// Read the results: the worker records and the request log of the server.
$acquires = [];
$passes = [];
$giveBacks = 0;
for ($w = 0; $w < $workers; ++$w) {
    /** @var array{acquires: list<array{float, float, list<string>, int, int, int}>, give_backs: int, passes: list<array{float, list<string>}>} $data */
    $data = json_decode((string) file_get_contents("{$tmp}-w{$w}.json"), true, 512, JSON_THROW_ON_ERROR);
    array_push($acquires, ...$data['acquires']);
    array_push($passes, ...$data['passes']);
    $giveBacks += $data['give_backs'];
    unlink("{$tmp}-w{$w}.json");
}
$lockSamples = null;
if ($sampler !== null) {
    /** @var array{samples: int, waiting: int} $lockSamples */
    $lockSamples = json_decode((string) file_get_contents("{$tmp}-sampler.json"), true, 512, JSON_THROW_ON_ERROR);
    unlink("{$tmp}-sampler.json");
}

$http = static function (string $method, string $path) use ($serverUrl): string {
    $body = file_get_contents($serverUrl.$path, false, stream_context_create([
        'http' => [
            'method' => $method,
        ],
    ]));
    if ($body === false) {
        throw new RuntimeException("{$method} {$path} failed");
    }

    return $body;
};
/** @var list<array{int, string, string|null}> $log */
$log = json_decode($http('GET', '/_times?prefix='.rawurlencode($prefix)), true, 512, JSON_THROW_ON_ERROR);
$http('DELETE', '/_requests?prefix='.rawurlencode($prefix));

$db = $pdo();
$count = static function (string $sql) use ($db): int {
    $statement = $db->prepare($sql);
    $statement->execute();

    return (int) $statement->fetchColumn();
};
$remaining = $count('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL');
$delivered = $count('SELECT COUNT(*) FROM webhook_deliveries WHERE outcome = 1');
$retried = $count('SELECT COUNT(*) FROM webhook_deliveries WHERE completed_at IS NULL AND attempts > 0');
$opened = $count('SELECT COUNT(*) FROM webhook_lanes WHERE opened_count > 0');

if ($log === []) {
    fwrite(STDERR, "The server logged no request\n");
    exit(1);
}

// Request times, all lanes and for each lane; the lane is the path segment after the prefix.
$times = [];
$laneTimes = [];
$byMessage = [];
foreach ($log as [$time, $path, $messageId]) {
    $times[] = $time;
    $lane = explode('?', substr($path, strlen($prefix)), 2)[0];
    $laneTimes[$lane][] = $time;
    if ($messageId !== null) {
        $byMessage[$messageId] = min($byMessage[$messageId] ?? PHP_INT_MAX, $time);
    }
}
sort($times);
$first = $times[0];
$last = $times[count($times) - 1];

/**
 * The most requests in a sliding window of 10 s with a step of 1 s, from the
 * first request; only windows that end at or before the last request.
 *
 * @param list<int> $sorted
 */
$slidingMax = static function (array $sorted) use ($first, $last): int {
    $max = 0;
    $from = 0;
    $to = 0;
    $n = count($sorted);
    for ($start = $first; $start + 10_000 <= $last + 1; $start += 1_000) {
        while ($from < $n && $sorted[$from] < $start) {
            ++$from;
        }
        $to = max($to, $from);
        while ($to < $n && $sorted[$to] < $start + 10_000) {
            ++$to;
        }
        $max = max($max, $to - $from);
    }

    return $max;
};
$maxAll = $slidingMax($times);
$maxLane = 0;
$maxLaneId = '-';
foreach ($laneTimes as $lane => $values) {
    sort($values);
    $value = $slidingMax($values);
    if ($value > $maxLane) {
        [$maxLane, $maxLaneId] = [$value, (string) $lane];
    }
}
$boundAll = (10 * $rps + $burst) * 1.01;
$boundLane = $laneRate === null ? null : (10 * $laneRate->perSecond + $laneRate->burst) * 1.01;

// Steady windows: [first + 10 s + 10 s * k, + 10 s), each ending 10 s or more before the last request.
/** @var list<SteadyWindow> $steady */
$steady = [];
for ($start = $first + 10_000; $start + 10_000 <= $last - 10_000; $start += 10_000) {
    $steady[] = new SteadyWindow(($start - $first) / 1000);
}
foreach ($times as $time) {
    $k = intdiv($time - $first - 10_000, 10_000);
    if ($time >= $first + 10_000 && $k < count($steady)) {
        ++$steady[$k]->requests;
    }
}
$bucketLimitedCalls = 0;
$durations = [];
foreach ($acquires as [$end, $duration, $limited, $wantedSum, $grantSum, $total]) {
    $durations[] = $duration;
    $cut = $grantSum < min($total, $wantedSum);
    if ($cut) {
        ++$bucketLimitedCalls;
    }
    if ($end < $first + 10_000) {
        continue;
    }
    $k = (int) floor(($end - $first - 10_000) / 10_000);
    if ($k < count($steady)) {
        $steady[$k]->bucketCut += $cut ? 1 : 0;
        $steady[$k]->limited += count($limited);
        foreach ($limited as $lane) {
            $steady[$k]->limitedLanes[$lane] = true;
        }
    }
}
sort($durations);

// D: the longest time from a grant to the first request of its pass.
$delays = [];
$unmatched = 0;
foreach ($passes as [$grantMs, $ids]) {
    $firstRequest = null;
    foreach ($ids as $id) {
        if (isset($byMessage[$id])) {
            $firstRequest = min($firstRequest ?? PHP_INT_MAX, $byMessage[$id]);
        } else {
            ++$unmatched;
        }
    }
    if ($firstRequest !== null && $grantMs > 0) {
        $delays[] = $firstRequest - $grantMs;
    }
}
sort($delays);

$minimum = 0.98 * 10 * $rps;
$limitActive = $steady !== [];
$lanesLimited = $steady !== [];
foreach ($steady as $window) {
    // The batch size alone can give a lane a grant below its wanted count; a bucket-cut call shows that a bucket set the grants.
    $limitActive = $limitActive && $window->limited > 0 && $window->bucketCut > 0 && $window->requests >= $minimum;
    $lanesLimited = $lanesLimited && $window->bucketCut > 0 && count($window->limitedLanes) === $lanes;
}

printf("backlog: %d rows; remaining unfinished %d (retried %d); delivered %d; lanes opened by the breaker %d\n", $backlog, $remaining, $retried, $delivered, $opened);
printf("requests in the server log: %d over %.1f s (first to last)\n", count($times), ($last - $first) / 1000);
printf("max requests in a sliding 10 s window, all lanes: %d (bound %.0f) %s\n", $maxAll, $boundAll, $maxAll <= $boundAll ? 'within' : 'ABOVE');
printf(
    "max requests in a sliding 10 s window, any lane: %d (%s)%s\n",
    $maxLane,
    $maxLaneId,
    $boundLane === null ? '' : sprintf(' (bound %.0f) %s', $boundLane, $maxLane <= $boundLane ? 'within' : 'ABOVE'),
);
foreach ($steady as $window) {
    printf(
        "steady window %4.0f-%4.0f s: %6d requests (%7.1f /s), limited grants %6d, bucket-cut calls %5d, lanes with a limited grant %d/%d\n",
        $window->fromS,
        $window->fromS + 10,
        $window->requests,
        $window->requests / 10,
        $window->limited,
        $window->bucketCut,
        count($window->limitedLanes),
        $lanes,
    );
}
printf("acquire calls %d (grant sum below min(total, wanted sum): %d), give-back calls %d; acquire p50 %s, p99 %s\n", count($acquires), $bucketLimitedCalls, $giveBacks, ms(percentile($durations, 0.5)), ms(percentile($durations, 0.99)));
printf("D (grant to the first request of its pass): max %s, p99 %s, passes %d, unmatched IDs %d\n", ms($delays === [] ? null : (float) $delays[count($delays) - 1]), ms(percentile($delays, 0.99)), count($passes), $unmatched);
if ($lockSamples !== null) {
    printf("lock wait on webhook_rate_limit: %d of %d samples (%.2f%%)\n", $lockSamples['waiting'], $lockSamples['samples'], $lockSamples['samples'] > 0 ? $lockSamples['waiting'] / $lockSamples['samples'] * 100 : 0);
}
printf("limit active (each steady window: a limited grant, a bucket-cut call and %.0f or more requests): %s\n", $minimum, $limitActive ? 'yes' : 'no');
if ($laneRate !== null) {
    printf("lanes limited (each steady window: a bucket-cut call, and a limited grant for each lane): %s\n", $lanesLimited ? 'yes' : 'no');
}

echo 'RESULT '.json_encode([
    'store' => $store,
    'lanes' => $lanes,
    'workers' => $workers,
    'rps' => $rps,
    'burst' => $burst,
    'lane_rps' => $laneRps,
    'batch' => $batch,
    'endpoint_ms' => $endpointMs,
    'seconds' => $seconds,
    'requests' => count($times),
    'span_s' => ($last - $first) / 1000,
    'max_window_all' => $maxAll,
    'bound_all' => $boundAll,
    'max_window_lane' => $maxLane,
    'bound_lane' => $boundLane,
    'steady' => array_map(static fn (SteadyWindow $w): array => [
        'from_s' => $w->fromS,
        'requests' => $w->requests,
        'rate' => $w->requests / 10,
        'limited' => $w->limited,
        'bucket_cut' => $w->bucketCut,
        'limited_lanes' => count($w->limitedLanes),
    ], $steady),
    'acquires' => count($acquires),
    'bucket_limited_calls' => $bucketLimitedCalls,
    'acquire_p50_ms' => percentile($durations, 0.5),
    'acquire_p99_ms' => percentile($durations, 0.99),
    'd_max_ms' => $delays === [] ? null : $delays[count($delays) - 1],
    'd_p99_ms' => percentile($delays, 0.99),
    'lock_wait_samples' => $lockSamples,
    'limit_active' => $limitActive,
    'lanes_limited' => $laneRate === null ? null : $lanesLimited,
    'remaining' => $remaining,
    'retried' => $retried,
    'delivered' => $delivered,
    'opened_lanes' => $opened,
], JSON_THROW_ON_ERROR)."\n";
