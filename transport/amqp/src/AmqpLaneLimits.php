<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use Freyr\MessageBroker\Outbox\LaneLimits;

/**
 * The RabbitMQ size limits as {@see LaneLimits}, for `OutboxProducer`.
 *
 * A user with a changed `max_message_size` or `frame_max` builds `LaneLimits` with own values.
 * RabbitMQ has no "text only" limit, so none is checked.
 */
final class AmqpLaneLimits
{
    /** RabbitMQ default `max_message_size`: 16 MiB, counted on the body. */
    public const int BROKER_MAX_MESSAGE_SIZE = 16_777_216;

    /** RabbitMQ default `frame_max` less the 8 bytes of framing: all headers of a message must fit one frame. */
    public const int BROKER_MAX_FRAME_PAYLOAD = 131_064;

    /** Body limit of the factory: 15 MiB, a margin of 1 MiB below the broker limit. */
    public const int MAX_BODY_BYTES = 15_728_640;

    /**
     * Header limit of the factory: 48 KiB of JSON. The AMQP table form is at most 2.5 times the JSON
     * form (122,880 bytes), and the rest of the frame holds the envelope and the content header.
     */
    public const int MAX_HEADER_BYTES = 49_152;

    public static function rabbitMqDefaults(): LaneLimits
    {
        return new LaneLimits(maxBodyBytes: self::MAX_BODY_BYTES, maxHeaderBytes: self::MAX_HEADER_BYTES);
    }
}
