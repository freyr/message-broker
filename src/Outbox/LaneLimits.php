<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox;

use InvalidArgumentException;

/**
 * Size limits that a transport enforces on a message, checked by
 * {@see OutboxProducer} at produce time so that a message the transport would
 * refuse for good never enters the outbox. `null` means no limit. A transport
 * adapter gives its own values (for RabbitMQ: `AmqpLaneLimits::rabbitMqDefaults()`).
 */
final readonly class LaneLimits
{
    /**
     * @param int|null $maxBodyBytes largest encoded body, in bytes
     * @param int|null $maxHeaderBytes largest JSON form of the produce-time headers, in bytes
     */
    public function __construct(
        public ?int $maxBodyBytes = null,
        public ?int $maxHeaderBytes = null,
    ) {
        if ($maxBodyBytes !== null && $maxBodyBytes < 1) {
            throw new InvalidArgumentException(sprintf('maxBodyBytes must be at least 1, got %d', $maxBodyBytes));
        }
        if ($maxHeaderBytes !== null && $maxHeaderBytes < 1) {
            throw new InvalidArgumentException(sprintf('maxHeaderBytes must be at least 1, got %d', $maxHeaderBytes));
        }
    }
}
