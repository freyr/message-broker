<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook\Http;

use InvalidArgumentException;

/**
 * Makes the three `webhook-` headers of the Standard Webhooks specification:
 * `webhook-id` (the message ID), `webhook-timestamp` (the send time in
 * seconds) and `webhook-signature`, which is `v1,` plus the base64
 * HMAC-SHA256 of `id.timestamp.body` over the exact body bytes.
 *
 * A secret has the form `whsec_` plus base64; the key is the decoded bytes.
 * With more than one secret (a secret change) the header holds one signature
 * for each secret, separated by a space. No secret is ever part of an
 * exception message.
 */
final readonly class StandardWebhooksSigner
{
    public static function isValidSecret(string $secret): bool
    {
        return self::key($secret) !== null;
    }

    /**
     * @param list<string> $secrets one or more secrets of the form whsec_ plus base64
     *
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public function headers(string $messageId, int $timestamp, string $body, array $secrets): array
    {
        if ($secrets === []) {
            throw new InvalidArgumentException('A webhook signature needs one or more secrets');
        }

        $content = $messageId.'.'.$timestamp.'.'.$body;
        $signatures = [];
        foreach ($secrets as $secret) {
            $key = self::key($secret);
            if ($key === null) {
                throw new InvalidArgumentException('A webhook secret must have the form whsec_ plus base64');
            }
            $signatures[] = 'v1,'.base64_encode(hash_hmac('sha256', $content, $key, true));
        }

        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => implode(' ', $signatures),
        ];
    }

    private static function key(string $secret): ?string
    {
        // base64_decode() in strict mode still skips whitespace; the pattern refuses it.
        if (preg_match('#^whsec_([A-Za-z0-9+/]+={0,2})\z#', $secret, $match) !== 1) {
            return null;
        }
        $key = base64_decode($match[1], true);

        return $key === false ? null : $key;
    }
}
