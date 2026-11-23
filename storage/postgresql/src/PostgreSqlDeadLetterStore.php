<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStat;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Symfony\Component\Uid\Uuid;

/**
 * PostgreSQL dead-letter table access (plain PDO) — the default DeadLetterStore implementation.
 */
final readonly class PostgreSqlDeadLetterStore implements DeadLetterStore
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Idempotent by id: when a row has the id (open or replayed), nothing
     * changes. Otherwise the upsert on the open pair (message_id, source):
     * an open row keeps its id and gets the data of the new failure; with no
     * open row, a new row. ON CONFLICT names the partial index
     * uq_dead_letters_open, so the store fails when the index is absent.
     */
    public function store(DeadLetter $deadLetter): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO dead_letters
                (id, source, message_id, message_name, body, headers, error_class, error_message, error_trace,
                 attempts, failed_at, replayable)
            SELECT
                CAST(:id AS UUID), CAST(:source AS TEXT), CAST(:message_id AS TEXT), CAST(:message_name AS TEXT),
                CAST(:body AS BYTEA), CAST(:headers AS JSONB), CAST(:error_class AS TEXT),
                CAST(:error_message AS TEXT), CAST(:error_trace AS TEXT), CAST(:attempts AS INTEGER),
                CAST(:failed_at AS TIMESTAMP(3)), CAST(:replayable AS BOOLEAN)
            WHERE NOT EXISTS (SELECT 1 FROM dead_letters WHERE id = CAST(:existing_id AS UUID))
            ON CONFLICT (message_id, source) WHERE replayed_at IS NULL DO UPDATE SET
                message_name = EXCLUDED.message_name,
                error_class = EXCLUDED.error_class,
                error_message = EXCLUDED.error_message,
                error_trace = EXCLUDED.error_trace,
                attempts = EXCLUDED.attempts,
                failed_at = EXCLUDED.failed_at,
                body = EXCLUDED.body,
                headers = EXCLUDED.headers,
                replayable = EXCLUDED.replayable
            SQL);
        $statement->bindValue('id', $deadLetter->id);
        $statement->bindValue('existing_id', $deadLetter->id);
        $statement->bindValue('source', $deadLetter->source);
        $statement->bindValue('message_id', $deadLetter->messageId);
        $statement->bindValue('message_name', $deadLetter->messageName);
        self::bindBody($statement, 'body', $deadLetter->body);
        $statement->bindValue('headers', json_encode($deadLetter->headers, JSON_THROW_ON_ERROR));
        $statement->bindValue('error_class', $deadLetter->errorClass);
        $statement->bindValue('error_message', $deadLetter->errorMessage);
        $statement->bindValue('error_trace', $deadLetter->errorTrace);
        $statement->bindValue('attempts', $deadLetter->attempts, PDO::PARAM_INT);
        $statement->bindValue(
            'failed_at',
            EpochMillis::toDateTime($deadLetter->failedAt)->format(self::DATETIME_FORMAT),
        );
        $statement->bindValue('replayable', $deadLetter->replayable, PDO::PARAM_BOOL);
        $statement->execute();
    }

    public function find(string $id): ?DeadLetter
    {
        // The id column is UUID: a malformed id (e.g. a typo at the CLI) is
        // "not found", not a database cast error.
        if (!Uuid::isValid($id)) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM dead_letters WHERE id = :id');
        $statement->execute([
            'id' => $id,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->hydrate($row);
    }

    /** @return list<DeadLetter> */
    public function list(DeadLetterFilter $filter, int $limit = 100, int $offset = 0): array
    {
        [$where, $parameters] = self::where($filter);
        $statement = $this->pdo->prepare(
            "SELECT * FROM dead_letters {$where} ORDER BY failed_at DESC, id DESC LIMIT :limit OFFSET :offset",
        );
        $statement->execute($parameters + [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function count(DeadLetterFilter $filter): int
    {
        [$where, $parameters] = self::where($filter);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM dead_letters {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    public function ids(DeadLetterFilter $filter, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("limit must be at least 1, got {$limit}");
        }
        [$where, $parameters] = self::where($filter);
        $statement = $this->pdo->prepare(
            "SELECT id FROM dead_letters {$where} ORDER BY failed_at ASC, id ASC LIMIT :limit",
        );
        $statement->execute($parameters + [
            'limit' => $limit,
        ]);

        /** @var list<string> $ids */
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $ids;
    }

    /** @return list<DeadLetterStat> */
    public function stats(DeadLetterFilter $filter): array
    {
        [$where, $parameters] = self::where($filter);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT message_name, source, error_class, COUNT(*) AS count,
                   MIN(failed_at) AS oldest_failed_at, MAX(failed_at) AS newest_failed_at
            FROM dead_letters {$where}
            GROUP BY message_name, source, error_class
            ORDER BY count DESC, message_name ASC, source ASC, error_class ASC
            SQL);
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): DeadLetterStat => new DeadLetterStat(
            messageName: self::string($row, 'message_name'),
            source: self::string($row, 'source'),
            errorClass: self::string($row, 'error_class'),
            count: (int) self::string($row, 'count'),
            oldestFailedAt: self::epochMilliseconds(self::string($row, 'oldest_failed_at')),
            newestFailedAt: self::epochMilliseconds(self::string($row, 'newest_failed_at')),
        ), $rows);
    }

    /**
     * Replay keeps the row for audit — marks replayed_at instead of deleting.
     * The conditional update takes the row lock: a second transaction that
     * marks the same row waits, and after the commit of the first it finds
     * replayed_at set and changes no row (READ COMMITTED). A value that is
     * not a UUID is "no row", with no query (the guard of find()).
     */
    public function markReplayed(string $id): bool
    {
        if (!Uuid::isValid($id)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE dead_letters SET replayed_at = :replayed_at WHERE id = :id AND replayed_at IS NULL',
        );
        $statement->execute([
            'replayed_at' => EpochMillis::toDateTime(EpochMillis::now())->format(self::DATETIME_FORMAT),
            'id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    public function purge(DeadLetterFilter $filter): int
    {
        [$where, $parameters] = self::where($filter);
        $statement = $this->pdo->prepare("DELETE FROM dead_letters {$where}");
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * The WHERE clause of a filter: fixed condition fragments only, and each
     * filter value as a bound parameter.
     *
     * @return array{string, array<string, int|string>}
     */
    private static function where(DeadLetterFilter $filter): array
    {
        $conditions = [];
        $parameters = [];
        if ($filter->messageName !== null) {
            $conditions[] = 'message_name = :message_name';
            $parameters['message_name'] = $filter->messageName;
        }
        if ($filter->source !== null) {
            $conditions[] = 'source = :source';
            $parameters['source'] = $filter->source;
        }
        if ($filter->errorClass !== null) {
            $conditions[] = 'error_class = :error_class';
            $parameters['error_class'] = $filter->errorClass;
        }
        if ($filter->messageId !== null) {
            $conditions[] = 'message_id = :message_id';
            $parameters['message_id'] = $filter->messageId;
        }
        if ($filter->failedFromMs !== null) {
            $conditions[] = 'failed_at >= :failed_from';
            $parameters['failed_from'] = EpochMillis::toDateTime($filter->failedFromMs)->format(self::DATETIME_FORMAT);
        }
        if ($filter->failedUntilMs !== null) {
            $conditions[] = 'failed_at < :failed_until';
            $parameters['failed_until'] = EpochMillis::toDateTime($filter->failedUntilMs)->format(self::DATETIME_FORMAT);
        }
        if ($filter->minAttempts !== null) {
            $conditions[] = 'attempts >= :min_attempts';
            $parameters['min_attempts'] = $filter->minAttempts;
        }
        if ($filter->replayed !== null) {
            $conditions[] = $filter->replayed ? 'replayed_at IS NOT NULL' : 'replayed_at IS NULL';
        }
        if ($filter->replayable !== null) {
            $conditions[] = $filter->replayable ? 'replayable' : 'NOT replayable';
        }

        return [$conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions), $parameters];
    }

    private static function bindBody(PDOStatement $statement, string $name, string $body): void
    {
        // BYTEA requires a binary bind; PARAM_LOB makes pdo_pgsql send binary
        // format. JSON text bound this way round-trips through BYTEA unchanged.
        $statement->bindValue($name, $body, PDO::PARAM_LOB);
    }

    private static function readBody(mixed $value): string
    {
        // pdo_pgsql returns BYTEA as a stream resource.
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        if (!is_string($value)) {
            throw new \RuntimeException('Body column is not a string or stream');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DeadLetter
    {
        $decodedHeaders = json_decode(self::string($row, 'headers'), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $headers */
        $headers = is_array($decodedHeaders) ? $decodedHeaders : [];

        return new DeadLetter(
            id: self::string($row, 'id'),
            source: self::string($row, 'source'),
            messageId: self::string($row, 'message_id'),
            messageName: self::string($row, 'message_name'),
            body: self::readBody($row['body'] ?? null),
            headers: $headers,
            errorClass: self::string($row, 'error_class'),
            errorMessage: self::string($row, 'error_message'),
            errorTrace: self::string($row, 'error_trace'),
            attempts: (int) self::string($row, 'attempts'),
            failedAt: self::epochMilliseconds(self::string($row, 'failed_at')),
            replayedAt: isset($row['replayed_at']) && is_string($row['replayed_at'])
                ? self::epochMilliseconds($row['replayed_at'])
                : null,
            replayable: self::bool($row, 'replayable'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new \RuntimeException("Dead letter column '{$column}' is not a string");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function bool(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;
        if (!is_bool($value)) {
            throw new \RuntimeException("Dead letter column '{$column}' is not a boolean");
        }

        return $value;
    }

    private static function epochMilliseconds(string $storedDateTime): int
    {
        try {
            $dateTime = new \DateTimeImmutable($storedDateTime, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \RuntimeException("Unparseable stored timestamp: {$storedDateTime}");
        }

        return EpochMillis::fromDateTime($dateTime);
    }
}
