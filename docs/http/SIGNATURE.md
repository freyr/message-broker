# Signature

`HttpDeliverySender` signs each request as the
[Standard Webhooks specification](https://www.standardwebhooks.com/) says, with
`StandardWebhooksSigner`. A receiver verifies the request with the secret of
its subscription, with a Standard Webhooks library or with the steps below.
The request and the configuration of the sender are in [SENDER.md](SENDER.md).

## The headers

| Header | Value |
|---|---|
| `webhook-id` | The message ID. All requests of one delivery, and the deliveries of one message to other subscriptions, have the same value, so a receiver can remove a duplicate. |
| `webhook-timestamp` | The send time of the `send()` call, in seconds since the Unix epoch. |
| `webhook-signature` | `v1,` plus the base64 HMAC-SHA256 of `<webhook-id>.<webhook-timestamp>.<body>`, over the exact body bytes. One signature for each secret of the subscription, with a space between them. |

## The secret

A secret has the form `whsec_` plus base64. The key of the HMAC is the decoded
bytes of the part after `whsec_`. A subscription with a secret of a different
form gets no request: the result is refused, with the reason code
`invalid-secret`.

**A change of the secret.** Give the subscription two secrets, the new one and
the old one, for the time of the change. Each request then has two signatures,
and a receiver with either secret verifies it. Remove the old secret when each
receiver uses the new one.

## Verification by a receiver

1. Read `webhook-id`, `webhook-timestamp` and the body bytes, before any
   parse of the body.
2. Compute the base64 HMAC-SHA256 of `<webhook-id>.<webhook-timestamp>.<body>`
   with the decoded key.
3. Compare it, in constant time, with each `v1,` signature of
   `webhook-signature`. One match is enough.
4. Refuse a timestamp that is too far from the time of the receiver, for
   example more than 5 minutes.

```php
$key = base64_decode(substr($secret, strlen('whsec_')), true);
$expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
$valid = false;
foreach (explode(' ', $signatureHeader) as $signature) {
    [$version, $value] = explode(',', $signature, 2) + [1 => ''];
    $valid = $valid || ($version === 'v1' && hash_equals($expected, $value));
}
```

The sender does not check that a receiver verifies the signature or the
timestamp.

## Version rule

The header names and the signature form follow the Standard Webhooks
specification. A change of them is a minor release with a note in the
changelog. The request carries no credential besides the signature.
