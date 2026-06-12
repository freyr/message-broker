<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

final readonly class PdoDeadLetterStore
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(
        private PDO $pdo,
        private Platform $platform, // @phpstan-ignore property.onlyWritten (dialect statements move here with slice 2)
    ) {}

    public function store(DeadLetter $deadLetter): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO dead_letters
                (id, source, message_id, message_name, body, headers, error_class, error_message, error_trace,
                 attempts, failed_at, replayed_at)
            VALUES
                (:id, :source, :message_id, :message_name, :body, :headers, :error_class, :error_message, :error_trace,
                 :attempts, :failed_at, NULL)
            SQL);
        $statement->execute([
            'id' => $deadLetter->id,
            'source' => $deadLetter->source,
            'message_id' => $deadLetter->messageId,
            'message_name' => $deadLetter->messageName,
            'body' => $deadLetter->body,
            'headers' => json_encode($deadLetter->headers, JSON_THROW_ON_ERROR),
            'error_class' => $deadLetter->errorClass,
            'error_message' => $deadLetter->errorMessage,
            'error_trace' => $deadLetter->errorTrace,
            'attempts' => $deadLetter->attempts,
            'failed_at' => EpochMillis::toDateTime($deadLetter->failedAt)->format(self::DATETIME_FORMAT),
        ]);
    }

    public function find(string $id): ?DeadLetter
    {
        $statement = $this->pdo->prepare('SELECT * FROM dead_letters WHERE id = :id');
        $statement->execute([
            'id' => $id,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return self::hydrate($row);
    }

    /** @return list<DeadLetter> */
    public function list(
        ?string $messageName = null,
        ?string $source = null,
        ?int $sinceMs = null,
        int $limit = 100,
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

        $where = $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            "SELECT * FROM dead_letters {$where} ORDER BY failed_at DESC, id DESC LIMIT :limit",
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::hydrate(...), $rows);
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

    public function purge(?int $olderThanMs = null): int
    {
        if ($olderThanMs === null) {
            $statement = $this->pdo->prepare('DELETE FROM dead_letters');
            $statement->execute();
        } else {
            $statement = $this->pdo->prepare('DELETE FROM dead_letters WHERE failed_at < :threshold');
            $statement->execute([
                'threshold' => EpochMillis::toDateTime($olderThanMs)->format(self::DATETIME_FORMAT),
            ]);
        }

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): DeadLetter
    {
        $decodedHeaders = json_decode(self::string($row, 'headers'), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $headers */
        $headers = is_array($decodedHeaders) ? $decodedHeaders : [];

        return new DeadLetter(
            id: self::string($row, 'id'),
            source: self::string($row, 'source'),
            messageId: self::string($row, 'message_id'),
            messageName: self::string($row, 'message_name'),
            body: self::string($row, 'body'),
            headers: $headers,
            errorClass: self::string($row, 'error_class'),
            errorMessage: self::string($row, 'error_message'),
            errorTrace: self::string($row, 'error_trace'),
            attempts: (int) self::string($row, 'attempts'),
            failedAt: self::epochMilliseconds(self::string($row, 'failed_at')),
            replayedAt: isset($row['replayed_at']) && is_string($row['replayed_at'])
                ? self::epochMilliseconds($row['replayed_at'])
                : null,
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

    private static function epochMilliseconds(string $storedDateTime): int
    {
        $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $storedDateTime, new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \RuntimeException("Unparseable stored timestamp: {$storedDateTime}");
        }

        return EpochMillis::fromDateTime($dateTime);
    }
}
