<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use InvalidArgumentException;

/**
 * Transport-native relay/publish configuration: AMQP vocabulary only
 * (exchange, confirms) — other transports would have entirely different
 * config classes.
 *
 * The relay routes by message name (the message type, e.g. 'order.placed').
 * There is no per-key routing and no ordering guarantee.
 */
final readonly class AmqpPublishConfig
{
    public function __construct(
        public string $exchange,
        public bool $publisherConfirms = true,
    ) {
        // This relay routes by message name to a NAMED exchange; the AMQP
        // default ('') exchange is not a supported target here.
        if ($exchange === '') {
            throw new InvalidArgumentException('AMQP exchange must be non-empty');
        }
    }
}
