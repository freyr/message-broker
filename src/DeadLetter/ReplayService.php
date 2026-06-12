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
            headers: $deadLetter->headers,
            createdAt: EpochMillis::now(),
        ));

        $this->deadLetters->markReplayed($deadLetter->id);
    }

    /** @return array<string, mixed> */
    private function decodeBody(DeadLetter $deadLetter): array
    {
        $document = json_decode($deadLetter->body, true);
        if (!is_array($document) || !isset($document['metadata'], $document['payload'])) {
            throw new \RuntimeException(
                "Dead letter '{$deadLetter->id}' body is not a replayable wire document ".'(non-JSON bodies require transport-specific replay, see slice 5)',
            );
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
