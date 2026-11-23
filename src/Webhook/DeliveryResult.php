<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/**
 * The result of one request of a {@see DeliverySender}. Each result has the
 * request time and a short text for the dead letter. The text never holds the
 * URL or a secret; for a transport error it is the error class and a fixed
 * category.
 *
 *  - delivered;
 *  - failed, with an optional retry time from the receiver (epoch
 *    milliseconds, for example from a Retry-After header);
 *  - refused for all time, with a reason code (for example `gone`).
 */
final readonly class DeliveryResult
{
    private function __construct(
        public DeliveryResultKind $kind,
        public int $requestMs,
        public string $text,
        public ?int $retryAt = null,
        public ?string $reasonCode = null,
    ) {}

    public static function delivered(int $requestMs, string $text = ''): self
    {
        return new self(DeliveryResultKind::Delivered, $requestMs, $text);
    }

    /** @param int|null $retryAt the retry time that the receiver asked for, epoch milliseconds */
    public static function failed(int $requestMs, string $text, ?int $retryAt = null): self
    {
        return new self(DeliveryResultKind::Failed, $requestMs, $text, $retryAt);
    }

    public static function refused(string $reasonCode, int $requestMs, string $text): self
    {
        return new self(DeliveryResultKind::Refused, $requestMs, $text, reasonCode: $reasonCode);
    }
}
