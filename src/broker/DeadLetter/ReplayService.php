<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\BrokerHeader;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Outbox\OutboxStore;
use Freyr\MessageBroker\Serializer\WireFormat;
use Freyr\MessageBroker\Storage\Transaction;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryStore;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
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
 * The outbox row has the headers of the dead letter minus the strip list of
 * BrokerHeader, plus x-replay-target = the source of the dead letter: only the
 * consumer of that queue processes the replay. The class keeps the last
 * created_at that it used, so it is not readonly.
 *
 * A webhook dead letter (source "webhook:<lane>") goes to the delivery store
 * instead: a new delivery row for that lane, with the message id, the message
 * name and the body bytes of the dead letter (no decode), attempts 0, and the
 * headers minus the strip list of BrokerHeader, with no x-replay-target. The
 * lane argument has no effect on it. replay() and check() return the
 * destination ({@see ReplayDestination}), so a caller can name it. The same transaction holds the
 * conditional markReplayed() first and the delivery insert second, so the
 * delivery store must work on the connection of the transaction too. A
 * service with no delivery store refuses a webhook dead letter.
 *
 * Refusals: DeadLetterNotFound, AlreadyReplayed (replayed_at is set, or
 * another process set it first), NotReplayable (the mark replayable = false,
 * a body that is not the canonical document, or for a webhook dead letter: no
 * delivery store, or a source with no valid lane). Each other throwable
 * propagates after the rollback.
 */
final class ReplayService
{
    /** The created_at of the last outbox row of this service, epoch ms. */
    private int $lastCreatedAt = 0;

    public function __construct(
        private readonly DeadLetterStore $deadLetters,
        private readonly OutboxStore $outbox,
        private readonly Transaction $transaction,
        private readonly WireFormat $wireFormat,
        private readonly ?BrokerEvents $events = null,
        private readonly ?DeliveryStore $deliveries = null,
    ) {}

    /**
     * @throws DeadLetterNotFound
     * @throws AlreadyReplayed
     * @return ReplayDestination the outbox lane $lane, or the webhook lane of the source
     *
     * @throws NotReplayable
     */
    public function replay(string $deadLetterId, string $lane = 'default'): ReplayDestination
    {
        $deadLetter = $this->open($deadLetterId);
        if (self::isWebhook($deadLetter)) {
            [$deliveries, $delivery] = $this->delivery($deadLetter);
            $this->markAndInsert($deadLetter, static fn () => $deliveries->insert([$delivery]));
            $this->recordReplayed($deadLetter, $deadLetter->messageName, $delivery->lane);

            return new ReplayDestination($delivery->lane, webhook: true);
        }

        [$messageName, $payload] = $this->decode($deadLetter);

        // The original id keeps consumer dedup state consistent. The original
        // outbox row may still exist (published, waiting for retention); the
        // new created_at gives the replayed row its own primary key.
        $record = new OutboxRecord(
            id: $deadLetter->messageId,
            lane: $lane,
            messageName: $messageName,
            body: $this->wireFormat->encode($messageName, $payload),
            headers: BrokerHeader::forReplay($deadLetter->headers, $deadLetter->source),
            createdAt: $this->nextCreatedAt(),
        );

        $this->markAndInsert($deadLetter, fn () => $this->outbox->insert($record));
        $this->recordReplayed($deadLetter, $messageName, $lane);

        return new ReplayDestination($lane, webhook: false);
    }

    /**
     * The read-only checks of replay() for a dry run; changes nothing.
     *
     * @return ReplayDestination the destination that replay() with the same arguments uses
     *
     * @throws DeadLetterNotFound
     * @throws AlreadyReplayed
     * @throws NotReplayable
     */
    public function check(string $deadLetterId, string $lane = 'default'): ReplayDestination
    {
        $deadLetter = $this->open($deadLetterId);
        if (self::isWebhook($deadLetter)) {
            return new ReplayDestination($this->delivery($deadLetter)[1]->lane, webhook: true);
        }
        $this->decode($deadLetter);

        return new ReplayDestination($lane, webhook: false);
    }

    /**
     * The later of now and the last value + 1 ms: two replays of one message
     * id in one millisecond (one message failed on two queues) get two
     * primary keys (id, created_at) in the outbox.
     */
    private function nextCreatedAt(): int
    {
        return $this->lastCreatedAt = max(EpochMillis::now(), $this->lastCreatedAt + 1);
    }

    /**
     * One replay transaction: the conditional mark first, then the insert,
     * then the commit; a rollback on each throwable.
     *
     * @param callable(): void $insert
     */
    private function markAndInsert(DeadLetter $deadLetter, callable $insert): void
    {
        $this->transaction->begin();
        try {
            // The mark is the first write: with two connections and the
            // transaction on the dead letter connection, a stop leaves a new
            // row and an open dead letter (a repeat), never a lost replay.
            if (!$this->deadLetters->markReplayed($deadLetter->id)) {
                throw new AlreadyReplayed($deadLetter->id);
            }
            $insert();
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
    }

    private function recordReplayed(DeadLetter $deadLetter, string $messageName, string $lane): void
    {
        $this->events?->record(BrokerEvents::REPLAYED, [
            'message_id' => $deadLetter->messageId,
            'message_name' => $messageName,
            'lane' => $lane,
            'target' => $deadLetter->source,
        ]);
    }

    /**
     * The checks of each replay that read no body: found, not replayed, not
     * marked not replayable.
     */
    private function open(string $deadLetterId): DeadLetter
    {
        $deadLetter = $this->deadLetters->find($deadLetterId) ?? throw new DeadLetterNotFound($deadLetterId);
        if ($deadLetter->replayedAt !== null) {
            throw new AlreadyReplayed($deadLetter->id);
        }
        if (!$deadLetter->replayable) {
            throw new NotReplayable($deadLetter->id, 'marked not replayable');
        }

        return $deadLetter;
    }

    private static function isWebhook(DeadLetter $deadLetter): bool
    {
        return str_starts_with($deadLetter->source, DeliveryLoop::SOURCE_PREFIX);
    }

    /**
     * The delivery store and the new delivery row of a webhook dead letter,
     * for the lane of its source. Its own id and a created_at of now give the
     * row its own primary key.
     *
     * @return array{0: DeliveryStore, 1: DeliveryRecord}
     */
    private function delivery(DeadLetter $deadLetter): array
    {
        $deliveries = $this->deliveries
            ?? throw new NotReplayable($deadLetter->id, 'the replay service has no delivery store for a webhook dead letter');

        try {
            return [$deliveries, new DeliveryRecord(
                id: Uuid::v7()->toString(),
                messageId: $deadLetter->messageId,
                lane: substr($deadLetter->source, strlen(DeliveryLoop::SOURCE_PREFIX)),
                messageName: $deadLetter->messageName,
                body: $deadLetter->body,
                headers: BrokerHeader::withoutReplayStrip($deadLetter->headers),
                createdAt: EpochMillis::now(),
            )];
        } catch (InvalidArgumentException $error) {
            throw new NotReplayable($deadLetter->id, "the source has no valid lane: {$error->getMessage()}");
        }
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
