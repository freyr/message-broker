<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use RuntimeException;

/**
 * A message is above a {@see LaneLimits} value, so the producer refuses it and
 * inserts no row. The exception holds names and sizes only, never the body or
 * a header value, so a log of it does not leak the payload.
 */
final class MessageTooLarge extends RuntimeException
{
    public const string PART_BODY = 'body';
    public const string PART_HEADERS = 'headers';

    /** @param self::PART_* $part */
    public function __construct(
        public readonly string $lane,
        public readonly string $messageId,
        public readonly string $messageName,
        public readonly string $part,
        public readonly int $size,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'Message %s (%s) on lane "%s" is refused: its %s is %d bytes, above the limit of %d bytes',
            $messageId,
            $messageName,
            $lane,
            $part,
            $size,
            $limit,
        ));
    }
}
