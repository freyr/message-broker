<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Serializer\WireFormat;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Time\EpochMillis;
use Throwable;

/**
 * Replay = re-enqueue a dead letter into the outbox under an explicitly chosen
 * lane, so redelivery rides the normal relay path (publisher confirms,
 * retries). The DLQ stores the reconstructed canonical document (metadata +
 * payload); replay re-encodes the payload through the produce-path WireFormat
 * (E2) so the outbox row matches the global format exactly.
 *
 * The lane is a parameter because dead letters are consumer-side records —
 * the producing lane is not part of the wire document.
 *
 * One replay is one transaction of the Transaction port: the conditional
 * markReplayed() first, then the outbox insert, then the commit. The two
 * stores and the transaction must work on ONE connection; when two cannot be
 * avoided, the transaction must wrap the connection of the dead letter store
 * (a stop then repeats a replay, and never loses one). Do not call replay()
 * inside a transaction that is open on the same connection.
 *
 * Refusals: DeadLetterNotFound, AlreadyReplayed (replayed_at is set, or
 * another process set it first), NotReplayable (the mark replayable = false,
 * or a body that is not the canonical document). Each other throwable
 * propagates after the rollback.
 */
final class ReplayService
{
    public function __construct(
        private readonly DeadLetterStore $deadLetters,
        private readonly OutboxStore $outbox,
        private readonly Transaction $transaction,
        private readonly WireFormat $wireFormat,
        private readonly ?BrokerEvents $events = null,
    ) {}

    /**
     * @throws DeadLetterNotFound
     * @throws AlreadyReplayed
     * @throws NotReplayable
     */
    public function replay(string $deadLetterId, string $lane = 'default'): void
    {
        [$deadLetter, $messageName, $payload] = $this->replayable($deadLetterId);

        // The original id keeps consumer dedup state consistent. The original
        // outbox row may still exist (published, waiting for retention); the
        // new created_at gives the replayed row its own primary key.
        $record = new OutboxRecord(
            id: $deadLetter->messageId,
            lane: $lane,
            messageName: $messageName,
            body: $this->wireFormat->encode($messageName, $payload),
            headers: $this->cleanHeadersForReplay($deadLetter->headers),
            createdAt: EpochMillis::now(),
        );

        $this->transaction->begin();
        try {
            // The mark is the first write: with two connections and the
            // transaction on the dead letter connection, a stop leaves an
            // outbox row and an open dead letter (a repeat), never a lost replay.
            if (!$this->deadLetters->markReplayed($deadLetter->id)) {
                throw new AlreadyReplayed($deadLetter->id);
            }
            $this->outbox->insert($record);
            $this->transaction->commit();
        } catch (Throwable $error) {
            try {
                $this->transaction->rollBack();
            } catch (Throwable) {
                // The first error is the one to report; a failed rollback
                // means the connection is gone and the database rolls back.
            }

            throw $error;
        }

        $this->events?->record(BrokerEvents::REPLAYED, [
            'message_id' => $deadLetter->messageId,
            'message_name' => $messageName,
            'lane' => $lane,
        ]);
    }

    /**
     * The read-only checks of replay() for a dry run; changes nothing.
     *
     * @throws DeadLetterNotFound
     * @throws AlreadyReplayed
     * @throws NotReplayable
     */
    public function check(string $deadLetterId): void
    {
        $this->replayable($deadLetterId);
    }

    /**
     * @return array{0: DeadLetter, 1: string, 2: array<string, mixed>} [deadLetter, messageName, payload]
     */
    private function replayable(string $deadLetterId): array
    {
        $deadLetter = $this->deadLetters->find($deadLetterId) ?? throw new DeadLetterNotFound($deadLetterId);
        if ($deadLetter->replayedAt !== null) {
            throw new AlreadyReplayed($deadLetter->id);
        }
        if (!$deadLetter->replayable) {
            throw new NotReplayable($deadLetter->id, 'marked not replayable');
        }

        return [$deadLetter, ...$this->decode($deadLetter)];
    }

    /**
     * Strip headers that must not carry over to a replayed outbox record:
     *
     * - x-attempt: dropping it resets the full retry budget for the replay.
     * - x-message-id / x-message-name / x-created-at: the envelope is re-derived
     *   from the DLQ document and re-exploded by the relay. The relay would
     *   overwrite stale copies anyway (its explode wins on key collision), but
     *   dropping them here keeps the replayed row's produce-time headers clean.
     *
     * All other headers (e.g. correlation_id) are preserved so traceability
     * context flows through the replay.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function cleanHeadersForReplay(array $headers): array
    {
        $strip = ['x-attempt', MetadataHeader::MESSAGE_ID, MetadataHeader::MESSAGE_NAME, MetadataHeader::CREATED_AT];

        return array_diff_key($headers, array_flip($strip));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} [messageName, payload]
     */
    private function decode(DeadLetter $deadLetter): array
    {
        $document = json_decode($deadLetter->body, true);
        if (!is_array($document) || !isset($document['metadata'], $document['payload'])) {
            throw new NotReplayable(
                $deadLetter->id,
                'body is not a replayable wire document (stage-1 dead letters store raw bytes and are not replayable)',
            );
        }

        $metadata = $document['metadata'];
        $payload = $document['payload'];
        if (!is_array($metadata) || !is_array($payload)) {
            throw new NotReplayable($deadLetter->id, 'document has non-object metadata/payload');
        }

        $messageName = $metadata['message_name'] ?? null;
        if (!is_string($messageName)) {
            throw new NotReplayable($deadLetter->id, 'metadata has no string message_name');
        }

        /** @var array<string, mixed> $payload */
        return [$messageName, $payload];
    }
}
