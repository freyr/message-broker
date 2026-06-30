<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp;

use InvalidArgumentException;

/**
 * Transport-native relay/publish configuration: AMQP vocabulary only
 * (exchange, confirms) — Kafka and SQS relays will have entirely different
 * config classes.
 *
 * The relay routes by message name (the message type, e.g. 'order.placed').
 * There is no per-key routing knob: best-effort per-key FIFO (consistent-hash
 * + single active consumer) is a postponed future lane mode, and its
 * producer-side seam was removed so the API doesn't imply a capability that
 * isn't built. See docs/research/2026-06-15-adr-amqp-fifo-best-effort.md.
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
