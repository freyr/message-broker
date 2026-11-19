<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterStore;
use Freyr\MessageBroker\Time\EpochMillis;
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

    public function store(DeadLetter $deadLetter): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO dead_letters
                (id, source, message_id, message_name, body, headers, error_class, error_message, error_trace,
                 attempts, failed_at, replayed_at, replayable)
            VALUES
                (:id, :source, :message_id, :message_name, :body, :headers, :error_class, :error_message, :error_trace,
                 :attempts, :failed_at, NULL, :replayable)
            SQL);
        $statement->bindValue('id', $deadLetter->id);
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
    public function list(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        int $limit = 100,
        int $offset = 0,
        ?bool $replayed = null,
    ): array {
        $conditions = [];
        $parameters = [];
        if ($messageName !== null) {
            $conditions[] = 'message_name = :message_name';
            $parameters['message_name'] = $messageName;
        }
        if ($source !== null) {
            $conditions[] = 'source = :source';
            $parameters['source'] = $source;
        }
        if ($sinceMs !== null) {
            $conditions[] = 'failed_at >= :since';
            $parameters['since'] = EpochMillis::toDateTime($sinceMs)->format(self::DATETIME_FORMAT);
        }
        if ($replayed !== null) {
            $conditions[] = $replayed ? 'replayed_at IS NOT NULL' : 'replayed_at IS NULL';
        }

        $where = $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            "SELECT * FROM dead_letters {$where} ORDER BY failed_at DESC, id DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function count(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        ?bool $replayed = null,
    ): int {
        $conditions = [];
        $parameters = [];
        if ($messageName !== null) {
            $conditions[] = 'message_name = :message_name';
            $parameters['message_name'] = $messageName;
        }
        if ($source !== null) {
            $conditions[] = 'source = :source';
            $parameters['source'] = $source;
        }
        if ($sinceMs !== null) {
            $conditions[] = 'failed_at >= :since';
            $parameters['since'] = EpochMillis::toDateTime($sinceMs)->format(self::DATETIME_FORMAT);
        }
        if ($replayed !== null) {
            $conditions[] = $replayed ? 'replayed_at IS NOT NULL' : 'replayed_at IS NULL';
        }

        $where = $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM dead_letters {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** Replay keeps the row for audit — marks replayed_at instead of deleting. */
    public function markReplayed(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE dead_letters SET replayed_at = :replayed_at WHERE id = :id');
        $statement->execute([
            'replayed_at' => EpochMillis::toDateTime(EpochMillis::now())->format(self::DATETIME_FORMAT),
            'id' => $id,
        ]);
    }

    public function purge(?string $messageName = null, ?string $source = null, ?int $olderThanMs = null): int
    {
        $conditions = [];
        $parameters = [];
        if ($messageName !== null) {
            $conditions[] = 'message_name = :message_name';
            $parameters['message_name'] = $messageName;
        }
        if ($source !== null) {
            $conditions[] = 'source = :source';
            $parameters['source'] = $source;
        }
        if ($olderThanMs !== null) {
            $conditions[] = 'failed_at < :threshold';
            $parameters['threshold'] = EpochMillis::toDateTime($olderThanMs)->format(self::DATETIME_FORMAT);
        }

        $where = $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);
        $statement = $this->pdo->prepare("DELETE FROM dead_letters {$where}");
        $statement->execute($parameters);

        return $statement->rowCount();
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
