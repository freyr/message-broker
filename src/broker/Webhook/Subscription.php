<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use InvalidArgumentException;

/**
 * One webhook subscription of the application. The id is the lane of its
 * delivery rows (1 to 64 characters, the column limit). The URL and the
 * secrets are never stored in a library table, never logged and never part
 * of an exception message; the delivery loop reads the subscription of a lane
 * at send time through {@see SubscriptionProvider}.
 *
 * More than one secret is the rule for a secret change: the sender signs each
 * request with each secret.
 */
final readonly class Subscription
{
    public const int MAX_ID_LENGTH = 64;

    /**
     * @param string $id the lane of the delivery rows of this subscription
     * @param list<string> $secrets one or more signature secrets
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $secrets,
    ) {
        $length = mb_strlen($id);
        if ($length === 0 || $length > self::MAX_ID_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Subscription id must be 1 to %d characters, got %d', self::MAX_ID_LENGTH, $length),
            );
        }
        if ($secrets === []) {
            throw new InvalidArgumentException(sprintf('Subscription "%s" needs one or more secrets', $id));
        }
    }
}
