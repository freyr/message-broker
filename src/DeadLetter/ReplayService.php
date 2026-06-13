<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;

/**
 * Replay = re-enqueue a dead letter into the outbox under an explicitly
 * chosen lane, so redelivery rides the normal relay path (publisher
 * confirms, ordering) instead of a side channel. Backs dlq:replay.
 *
 * The lane is a parameter because dead letters are consumer-side records —
 * the producing lane is not part of the wire document.
 */
final readonly class ReplayService
{
    public function __construct(
        private PdoDeadLetterStore $deadLetters,
        private OutboxStore $outbox,
    ) {}

    public function replay(string $deadLetterId, string $lane = 'default'): void
    {
        $deadLetter = $this->deadLetters->find($deadLetterId);
        if ($deadLetter === null) {
            throw new \RuntimeException("Dead letter '{$deadLetterId}' not found");
        }

        $this->outbox->insert(new OutboxRecord(
            id: $deadLetter->messageId,   // original id: consumer dedup state stays consistent
            lane: $lane,
            messageName: $deadLetter->messageName,
            // The wire document carries no producer key; the message id is a
            // stable, unique fallback for transport-level keying on replay.
            key: $deadLetter->messageId,
            body: $this->decodeBody($deadLetter),
            headers: $this->cleanHeadersForReplay($deadLetter->headers),
            createdAt: EpochMillis::now(),
        ));

        $this->deadLetters->markReplayed($deadLetter->id);
    }

    /**
     * Strip headers that must not carry over to a replayed outbox record:
     *
     * - x-attempt: dropping it resets the full retry budget for the replay
     *   (the counter would resume from the dead-letter's terminal attempt
     *   and exhaust on the very first failure, giving zero effective retries).
     * - x-message-id, x-message-name, x-created-at: derived by the lane's
     *   serializer at relay time; keeping stale values from the previous
     *   delivery would override freshly derived metadata for Avro lanes and
     *   be silent noise on JSON lanes.
     *
     * All other headers (e.g. correlation_id) are preserved so that
     * traceability context flows through the replay.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function cleanHeadersForReplay(array $headers): array
    {
        $strip = ['x-attempt', 'x-message-id', 'x-message-name', 'x-created-at'];

        return array_diff_key($headers, array_flip($strip));
    }

    /** @return array<string, mixed> */
    private function decodeBody(DeadLetter $deadLetter): array
    {
        $document = json_decode($deadLetter->body, true);
        if (!is_array($document) || !isset($document['metadata'], $document['payload'])) {
            throw new \RuntimeException(
                "Dead letter '{$deadLetter->id}' body is not a replayable wire document ".'(stage-1 dead letters store raw bytes and are not replayable)',
            );
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
