<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use Freyr\MessageBroker\Storage\Platform;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;

/**
 * PDO-backed outbox table access. Constructed with the application's
 * connection on the produce side (transaction joining) and with a dedicated
 * connection inside relay processes.
 */
final readonly class OutboxStore
{
    public function __construct(
        private PDO $pdo,
        private Platform $platform,
    ) {}

    public function insert(OutboxRecord $record): void
    {
        $statement = $this->pdo->prepare($this->platform->insertOutboxSql());
        $statement->execute([
            'id' => $record->id,
            'lane' => $record->lane,
            'message_name' => $record->messageName,
            'message_key' => $record->key,
            'body' => json_encode($record->body, JSON_THROW_ON_ERROR),
            'headers' => json_encode($record->headers, JSON_THROW_ON_ERROR),
            'created_at' => EpochMillis::toDateTime($record->createdAt)->format('Y-m-d H:i:s.v'),
            'available_at' => EpochMillis::toDateTime($record->availableAt ?? $record->createdAt)->format(
                'Y-m-d H:i:s.v'
            ),
        ]);
    }

    /**
     * Exclusive, session-scoped ownership of one lane via advisory lock.
     * Self-releases if this connection dies — crash recovery for free.
     * One relay per lane = total in-order publishing per lane.
     */
    public function tryAcquireLane(string $lane): bool
    {
        $statement = $this->pdo->prepare($this->platform->tryAcquireLaneSql());
        $statement->execute([
            'lane' => $lane,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * Contiguous prefix of one OWNED lane, ordered by id (UUIDv7 = time).
     * Caller checks head eligibility (available_at) on the first row only —
     * a backing-off head blocks the whole lane; nothing overtakes (D17).
     *
     * @return list<OutboxRecord>
     */
    public function lanePrefix(string $lane, int $limit): array
    {
        $statement = $this->pdo->prepare($this->platform->selectLanePrefixSql());
        $statement->bindValue('lane', $lane);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Successful publish — the row's job is done. Rows leave ONLY this way. */
    public function delete(string $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM outbox_messages WHERE id = :id');
        $statement->execute([
            'id' => $id,
        ]);
    }

    /**
     * Transient publish failure: bump the head's available_at, increment
     * attempts. The lane waits out the backoff — there is no exhaustion,
     * no relay-side DLQ; a long-blocked lane is an operational alert.
     */
    public function scheduleRetry(string $id, int $availableAtMs): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE outbox_messages SET available_at = :available_at, attempts = attempts + 1 WHERE id = :id',
        );
        $statement->execute([
            'available_at' => EpochMillis::toDateTime($availableAtMs)->format('Y-m-d H:i:s.v'),
            'id' => $id,
        ]);
    }

    private static function hydrate(array $row): OutboxRecord
    {
        return new OutboxRecord(
            id: $row['id'],
            lane: $row['lane'],
            messageName: $row['message_name'],
            key: $row['message_key'],
            body: json_decode((string) $row['body'], true, 512, JSON_THROW_ON_ERROR),
            headers: json_decode((string) $row['headers'], true, 512, JSON_THROW_ON_ERROR),
            createdAt: self::epochMilliseconds($row['created_at']),
            attempts: (int) $row['attempts'],
            availableAt: self::epochMilliseconds($row['available_at']),
        );
    }

    private static function epochMilliseconds(string $storedDateTime): int
    {
        $dateTime = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.v',
            $storedDateTime,
            new \DateTimeZone('UTC'),
        );

        if ($dateTime === false) {
            throw new \RuntimeException("Unparseable stored timestamp: {$storedDateTime}");
        }

        return EpochMillis::fromDateTime($dateTime);
    }
}
