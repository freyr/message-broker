<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Freyr\MessageBroker\Serializer\MetadataHeader;

/**
 * The one place in the core for the names of the library headers, and the
 * list of the headers that a replay strips from the new outbox row.
 * {@see MetadataHeader} stays the envelope convention.
 *
 * The header names are part of the wire contract of the library: a change of
 * a name is a breaking change. The names are strings, so the core imports no
 * class of an adapter.
 */
final class BrokerHeader
{
    /**
     * The work queue (the source of the dead letter) that must process a
     * replay. A consumer of a different queue acks the delivery with no
     * dispatch. Reserved: only the library writes it.
     */
    public const string REPLAY_TARGET = 'x-replay-target';

    /** The header of the transport dead letter sink. */
    public const string DEAD_LETTER = 'x-dead-letter';

    /**
     * The headers that a replay removes before it adds the replay target:
     *
     * - x-attempt: the replay gets the full retry budget.
     * - x-message-id, x-message-name, x-created-at: the relay makes the
     *   envelope from the row columns.
     * - x-replay-target: a dead letter of a replayed message holds the old target.
     * - x-delivery-count: a copied count shows as a stale count on the first
     *   delivery, and can trip the delivery limit at once.
     * - x-death: broker data of the old delivery.
     * - x-dead-letter: a stale copy makes an import rebuild the old failure.
     *
     * @var list<string>
     */
    public const array REPLAY_STRIP = [
        'x-attempt',
        MetadataHeader::MESSAGE_ID,
        MetadataHeader::MESSAGE_NAME,
        MetadataHeader::CREATED_AT,
        self::REPLAY_TARGET,
        'x-delivery-count',
        'x-death',
        self::DEAD_LETTER,
    ];

    /**
     * The name prefixes of the broker headers that a replay removes as well
     * (x-first-death-queue, x-last-death-reason, ...).
     *
     * @var list<string>
     */
    public const array REPLAY_STRIP_PREFIXES = ['x-first-death-', 'x-last-death-'];

    /**
     * The headers of a dead letter for its replay: minus the strip list and
     * the strip prefixes, plus the replay target.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public static function forReplay(array $headers, string $target): array
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            if (!in_array($name, self::REPLAY_STRIP, true) && !self::hasStripPrefix($name)) {
                $kept[$name] = $value;
            }
        }
        $kept[self::REPLAY_TARGET] = $target;

        return $kept;
    }

    private static function hasStripPrefix(string $name): bool
    {
        foreach (self::REPLAY_STRIP_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
