<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\Webhook\PermitDivision;
use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\RateLimiter;
use Freyr\MessageBroker\Webhook\RateLimits;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The token buckets of the RateLimiter port in the table webhook_rate_limit
 * (plain PDO): one row for each bucket, the name '' for the global bucket and
 * the lane ID for a lane bucket. The rows hold the tokens and the time of the
 * last refill only; the rates and the burst capacities come from each call.
 *
 * The buckets of a call are '' (only when the global rate is not null) and
 * the lanes of the call with a limit. A call with no bucket does not touch the
 * database. Each call:
 *
 *  1. In autocommit, one INSERT ... ON CONFLICT (name) DO NOTHING adds the
 *     rows that do not exist, sorted by name COLLATE "C", with full tokens.
 *     It commits before any lock of the call, so two workers that insert the
 *     same new rows wait for each other in one order only. The store skips
 *     it for the names that it already saw; that cache is a hint only.
 *  2. One explicit transaction locks the rows with SELECT ... ORDER BY name
 *     COLLATE "C" FOR UPDATE. '' sorts first, so the global row is always
 *     the first lock, and all calls lock in one order. When a row is missing
 *     (a different connection deleted it), the store rolls back, inserts the
 *     rows again and repeats the lock one time; a second miss throws.
 *  3. PHP computes the refill and the division with PermitDivision and the
 *     database time. A database time earlier than the stored time gives a
 *     refill of 0 and keeps the stored time.
 *  4. One UPDATE ... FROM jsonb_to_recordset writes all rows, and the
 *     transaction commits.
 *
 * Each statement takes the names as one jsonb parameter, so its text is the
 * same for each number of lanes, and pdo_pgsql and a pooler keep one prepared
 * statement for it. Only transaction-scoped row locks: the store works behind
 * a transaction-mode pooler. Construct it with the dedicated connection of
 * the delivery worker, which is in no transaction of the application.
 */
final class PostgreSqlRateLimiter implements RateLimiter
{
    private const string GLOBAL_BUCKET = '';

    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO webhook_rate_limit (name, tokens, refilled_at)
        SELECT b.name, b.tokens, date_trunc('milliseconds', clock_timestamp())
        FROM jsonb_to_recordset(CAST(:buckets AS JSONB)) AS b(name TEXT, tokens DOUBLE PRECISION)
        ORDER BY b.name COLLATE "C"
        ON CONFLICT (name) DO NOTHING
        SQL;

    private const string LOCK_SQL = <<<'SQL'
        SELECT name, tokens,
            CAST(floor(EXTRACT(EPOCH FROM refilled_at) * 1000) AS BIGINT) AS refilled_ms,
            CAST(floor(EXTRACT(EPOCH FROM clock_timestamp()) * 1000) AS BIGINT) AS now_ms
        FROM webhook_rate_limit
        WHERE name IN (SELECT jsonb_array_elements_text(CAST(:names AS JSONB)))
        ORDER BY name COLLATE "C"
        FOR UPDATE
        SQL;

    private const string UPDATE_SQL = <<<'SQL'
        UPDATE webhook_rate_limit t
        SET tokens = v.tokens, refilled_at = TIMESTAMPTZ 'epoch' + v.at * INTERVAL '1 millisecond'
        FROM jsonb_to_recordset(CAST(:buckets AS JSONB)) AS v(name TEXT, tokens DOUBLE PRECISION, at BIGINT)
        WHERE t.name = v.name
        SQL;

    /** @var array<array-key, bool> true for each name whose row this store saw; a hint, a row can be gone */
    private array $known = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function acquire(array $wanted, int $total, RateLimits $limits): array
    {
        return $this->withBuckets(
            $this->rates(array_keys($wanted), $limits),
            static function (array $tokens) use ($wanted, $total): array {
                $available = [];
                foreach ($wanted as $lane => $count) {
                    $available[$lane] = array_key_exists($lane, $tokens) ? min($count, (int) floor($tokens[$lane])) : $count;
                }
                $grant = array_key_exists(self::GLOBAL_BUCKET, $tokens) ? min($total, (int) floor($tokens[self::GLOBAL_BUCKET])) : $total;
                $granted = PermitDivision::divide($available, $grant);

                $after = [];
                foreach ($tokens as $name => $bucketTokens) {
                    $after[$name] = $bucketTokens - ($name === self::GLOBAL_BUCKET ? array_sum($granted) : $granted[$name]);
                }

                return [$granted, $after];
            },
        );
    }

    public function giveBack(array $unused, RateLimits $limits): void
    {
        $rates = $this->rates(array_keys($unused), $limits);
        $this->withBuckets(
            $rates,
            static function (array $tokens) use ($unused, $rates): array {
                $after = [];
                foreach ($tokens as $name => $bucketTokens) {
                    $returned = $name === self::GLOBAL_BUCKET ? array_sum($unused) : $unused[$name];
                    $after[$name] = min($rates[$name]->burst, $bucketTokens + $returned);
                }

                return [[], $after];
            },
        );
    }

    /**
     * The buckets of a call: '' for the global rate when it is not null, and each lane with a limit.
     *
     * @param list<array-key> $lanes
     *
     * @return array<array-key, Rate> the rate of each bucket, by name
     */
    private function rates(array $lanes, RateLimits $limits): array
    {
        $rates = [];
        if ($limits->global !== null) {
            $rates[self::GLOBAL_BUCKET] = $limits->global;
        }
        foreach ($lanes as $lane) {
            $rate = $limits->forLane((string) $lane);
            if ($rate !== null) {
                $rates[$lane] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Run one call on its buckets: insert the missing rows, lock the rows,
     * give the refilled tokens of each bucket to $compute, and write the
     * tokens that it returns.
     *
     * @param array<array-key, Rate> $rates
     * @param callable(array<array-key, float>): array{array<array-key, int>, array<array-key, float>} $compute the result of the call and the tokens of each bucket after it
     *
     * @return array<array-key, int> the result of $compute
     */
    private function withBuckets(array $rates, callable $compute): array
    {
        if ($rates === []) {
            return $compute([])[0];
        }

        $this->insert(array_filter($rates, fn (int|string $name): bool => !($this->known[$name] ?? false), ARRAY_FILTER_USE_KEY));
        $outcome = $this->exchange($rates, $compute);
        if ($outcome === null) {
            // A different connection deleted a row after the insert.
            $this->insert($rates);
            $outcome = $this->exchange($rates, $compute)
                ?? throw new RuntimeException('A row of webhook_rate_limit is missing after its insert, two times.');
        }

        return $outcome[0];
    }

    /**
     * @param array<array-key, Rate> $rates
     * @param callable(array<array-key, float>): array{array<array-key, int>, array<array-key, float>} $compute
     *
     * @return array{array<array-key, int>}|null null when a row of $rates is missing; the transaction is then rolled back
     */
    private function exchange(array $rates, callable $compute): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(self::LOCK_SQL);
            $lock->execute([
                // A numeric name is an integer key; jsonb_array_elements_text gives its text.
                'names' => self::json(array_keys($rates)),
            ]);
            /** @var list<array{name: string, tokens: float, refilled_ms: int, now_ms: int}> $rows pdo_pgsql gives the native types */
            $rows = $lock->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) < count($rates)) {
                $this->pdo->rollBack();

                return null;
            }

            $tokens = [];
            $times = [];
            foreach ($rows as $row) {
                $storedAt = $row['refilled_ms'];
                $now = max($row['now_ms'], $storedAt);
                $tokens[$row['name']] = PermitDivision::refill($row['tokens'], $storedAt, $now, $rates[$row['name']]);
                $times[$row['name']] = $now;
            }
            [$result, $after] = $compute($tokens);

            $buckets = [];
            foreach ($after as $name => $bucketTokens) {
                $buckets[] = [
                    'name' => $name,
                    'tokens' => $bucketTokens,
                    'at' => $times[$name],
                ];
            }
            $this->pdo->prepare(self::UPDATE_SQL)->execute([
                'buckets' => self::json($buckets),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->known += array_fill_keys(array_keys($rates), true);

        return [$result];
    }

    /** @param array<array-key, Rate> $rates the rows to insert when they do not exist, each with the burst of its rate */
    private function insert(array $rates): void
    {
        if ($rates === []) {
            return;
        }
        $buckets = [];
        foreach ($rates as $name => $rate) {
            $buckets[] = [
                'name' => $name,
                'tokens' => $rate->burst,
            ];
        }
        $this->pdo->prepare(self::INSERT_SQL)->execute([
            'buckets' => self::json($buckets),
        ]);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
