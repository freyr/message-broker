<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Serializer\WireFormat;
use Freyr\MessageBroker\Time\EpochMillis;
use RuntimeException;

/**
 * Replay = re-enqueue a dead letter into the outbox under an explicitly chosen
 * lane, so redelivery rides the normal relay path (publisher confirms,
 * ordering). The DLQ stores the reconstructed canonical document (metadata +
 * payload); replay re-encodes the payload through the produce-path WireFormat
 * (E2) so the outbox row matches the global format exactly.
 *
 * The lane is a parameter because dead letters are consumer-side records —
 * the producing lane is not part of the wire document.
 */
final readonly class ReplayService
{
    public function __construct(
        private PdoDeadLetterStore $deadLetters,
        private OutboxStore $outbox,
        private WireFormat $wireFormat,
    ) {}

    public function replay(string $deadLetterId, string $lane = 'default'): void
    {
        $deadLetter = $this->deadLetters->find($deadLetterId)
            ?? throw new RuntimeException("Dead letter '{$deadLetterId}' not found");

        [$messageName, $payload] = $this->decode($deadLetter);

        $now = EpochMillis::now();

        $this->outbox->insert(new OutboxRecord(
            id: $deadLetter->messageId,   // original id: consumer dedup state stays consistent
            lane: $lane,
            // The wire document carries no producer key; the message id is a
            // stable, unique fallback for transport-level keying on replay.
            key: $deadLetter->messageId,
            metadata: [
                'message_name' => $messageName,
                'message_id' => $deadLetter->messageId,
                'created_at' => $now,
            ],
            body: $this->wireFormat->encode($messageName, $payload),
            headers: $this->cleanHeadersForReplay($deadLetter->headers),
            createdAt: $now,
        ));

        $this->deadLetters->markReplayed($deadLetter->id);
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
            throw new RuntimeException(
                "Dead letter '{$deadLetter->id}' body is not a replayable wire document ".'(stage-1 dead letters store raw bytes and are not replayable)',
            );
        }

        $metadata = $document['metadata'];
        $payload = $document['payload'];
        if (!is_array($metadata) || !is_array($payload)) {
            throw new RuntimeException("Dead letter '{$deadLetter->id}' document has non-object metadata/payload");
        }

        $messageName = $metadata['message_name'] ?? null;
        if (!is_string($messageName)) {
            throw new RuntimeException("Dead letter '{$deadLetter->id}' metadata has no string message_name");
        }

        /** @var array<string, mixed> $payload */
        return [$messageName, $payload];
    }
}
