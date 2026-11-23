# HTTP sender

This document describes `HttpDeliverySender`, the HTTP adapter of the webhook
queue: the request, the configuration, the results, the timeouts and the
connections. The model of the queue, the ports and the delivery loop are in
[../WEBHOOKS.md](../WEBHOOKS.md). The signature is in
[SIGNATURE.md](SIGNATURE.md). The private network rule and the proxy rule are
in [NETWORK.md](NETWORK.md).

| Class | Implements | Constructor |
|---|---|---|
| `HttpDeliverySender` | `Webhook\DeliverySender` | `HttpSenderConfig $config = new HttpSenderConfig()`, `?HttpClientInterface $client = null`, `LoggerInterface $logger = new NullLogger()`, `?Closure $resolver = null` |
| `HttpSenderConfig` | — | the values in [Configuration](#configuration) |
| `StandardWebhooksSigner` | — | none |
| `ResponseClassifier` | — | none |

The sender uses Symfony HttpClient (`symfony/http-client` ^8.1) and the IP
list of `symfony/http-foundation` ^8.1. It needs no event loop in the worker:
one `send()` call starts the requests of the batch in parallel and returns
when each request has a result.

```php
use Freyr\MessageBroker\Adapter\Http\HttpDeliverySender;
use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;

$sender = new HttpDeliverySender(new HttpSenderConfig(maxHostConnections: 100), logger: $logger);

$loop = new DeliveryLoop(
    store: $deliveryStore,
    sender: $sender,
    subscriptions: $subscriptions,
    config: new DeliveryLoopConfig(batchSize: 100, leaseMs: 60_000),
);
```

## The request

`send()` builds one request for each delivery of the claim:

- Method `POST`. The body is the stored bytes of the delivery, with no change.
- `content-type: application/json`.
- `webhook-id`, `webhook-timestamp` and `webhook-signature` (see
  [SIGNATURE.md](SIGNATURE.md)).
- `x-message-name` and `x-created-at` (epoch milliseconds), with the names
  that the relay gives to the AMQP headers.
- `User-Agent`: the value of `userAgent`.
- The produce-time headers of the delivery are not sent.
- A CR, LF or NUL byte in the message ID or the message name is sent
  percent-encoded (`%0D`, `%0A`, `%00`).

The sender follows no redirect, keeps the TLS certificate checks on
(`verify_peer` and `verify_host` on each request), and sets the proxy options
on each request. No configuration turns the certificate checks off.

## Configuration

`HttpSenderConfig` holds the values of one sender. A value that is not valid
gives an `InvalidArgumentException` in the constructor.

| Value | Default | Purpose |
|---|---|---|
| `totalTimeoutMs` | `10_000` (10 s) | The total limit of one request, and the deadline of the transfers of one `send()` call. 1 or more. |
| `connectTimeoutMs` | `3_000` (3 s) | The limit of the DNS lookup, the TCP connect and the TLS handshake of one request. 1 or more, and not longer than `totalTimeoutMs`. |
| `dnsAllowanceMs` | `5_000` (5 s) | The time of one `send()` call for the DNS lookups of the private network check, before the transfers start. 1 or more when the check is on; 0 or more when it is off. |
| `maxHostConnections` | `100` | The parallel connections for each host. Use the batch size of the loop or more (see [Connections](#connections)). |
| `clientResetIntervalMs` | `300_000` (5 min) | The interval of the reset of the client. A reset clears the kept addresses of the private network check and closes the kept connections. |
| `proxy` | `null` | An `http` or `https` proxy URL. With a proxy, the private network check is off (see [NETWORK.md](NETWORK.md)). |
| `blockPrivateNetworks` | `true` | Refuse a destination in a private network (see [NETWORK.md](NETWORK.md)). |
| `allowHttp` | `false` | Permit `http` URLs. By default, only `https` URLs are sent. |
| `userAgent` | `freyr-message-broker-http` | The value of the `User-Agent` header. |

**Your own client.** The second constructor argument takes your
`HttpClientInterface`, for example a client with your own certificate
authority file. Its connection limit for each host stays as you configured
it: `maxHostConnections` applies only to the client that the sender creates.
The sender sets the redirect, certificate, timeout and proxy options on each
request, so your client cannot change them.

## The results

`send()` returns one `Webhook\DeliveryResult` for each delivery, keyed by the
delivery ID, in the order of the input.

| Response or event | Result | Text |
|---|---|---|
| 2xx | delivered | `HTTP <status>` and the body start |
| 410 | refused, reason code `gone` | `HTTP 410` and the body start |
| 429 or 503 | failed, with the retry time of `Retry-After` (seconds or an HTTP date) | `HTTP <status>` and the body start |
| Each other status, also 3xx and the other 4xx | failed | `HTTP <status>` and the body start |
| A timeout, or a request that is still open at the deadline | failed | `<exception class> (timeout)` or `deadline (timeout)` |
| A connect error | failed | `<exception class> (connect)` |
| A TLS error | failed | `<exception class> (tls)` |
| A host that does not resolve, or no time left in the DNS allowance | failed | `no address (dns)`, `dns allowance used up (dns)` or `<exception class> (dns)` |
| A host name that resolves to a private address, with the check on | failed | `<exception class> (blocked)` |
| A URL that is not valid, a URL with credentials, a scheme that is not permitted, an IP literal in a private network | refused, reason code `invalid-destination` | a fixed sentence |
| A secret that does not have the form `whsec_` plus base64 | refused, reason code `invalid-secret` | a fixed sentence |

- The body start is the first 500 bytes of the response body, as valid UTF-8
  with no NUL byte. The sender reads at most one chunk more than 500 bytes,
  and does not trust the body.
- A text never holds the URL, a secret or the message of an exception:
  Symfony puts the full URL into that message.
- The request time of a result is the `total_time` of the client: the time
  from the start of the transfer to its result.
- The delivery loop puts the reason code of a refusal into its dead letter
  and into the event `webhook.dead_lettered`. Use the reason code `gone` to
  disable a subscription in your application.
- The core limits a retry time from `Retry-After` (see the default retry
  policy in [../WEBHOOKS.md](../WEBHOOKS.md#the-default-retry-policy)).

`send()` does not throw for a failed request. It throws only for an error in
its own code or configuration, and the loop then stops.

## Time of one send() call

1. With the private network check on, the sender resolves each new host name
   of the batch, one after the other, inside the DNS allowance of the call.
2. All requests start. The sender reads the responses as they arrive.
3. One deadline covers the transfers: `totalTimeoutMs` after they start, and
   never later than `maxSendMs()` after the start of `send()`. A request that
   is still open at the deadline fails with the category timeout.

`maxSendMs()` is `totalTimeoutMs` plus `dnsAllowanceMs`. The delivery loop
refuses to start when its lease is not longer than this value. A slow endpoint
does not delay the results of the other requests, but `send()` returns after
the slowest request of the batch.

**The DNS allowance.** A host that does not resolve fails with the category
`dns` and gets no request. When the allowance is used up, each pair of the call
with a host that is not kept yet fails with the category `dns` and gets no
request. A resolved address is kept until the reset of the client; a failed
lookup is not kept.

**The known limit of the DNS allowance.** PHP cannot stop a lookup of the
operating system (`getaddrinfo()` has no timeout). A lookup that starts before
the end of the allowance and takes longer than the time that is left of
`maxSendMs()` makes `send()` return late by that excess. With the default
lookup, the excess is at most two resolver timeouts of the operating system:
the A lookup, then the AAAA lookup (each is the `timeout` times the `attempts`
of `resolv.conf`). Such a pair fails with the category `dns`. A host whose name
server does not answer thus delays each batch of the worker that holds its
lane. Keep the lease of the loop longer than `maxSendMs()` plus this excess.

## Connections

**`maxHostConnections` must be the batch size of the loop or more.** All
deliveries of one batch can go to one host. With fewer connections, the
requests that wait for a connection start late and use the deadline of the
call. The default of Symfony HttpClient is 6 connections for each host, which
limits one host to about 120 requests each second at a response time of 50 ms.
The default of the sender is 100, the default batch size of the loop.

The client keeps connections open between batches, so a worker reuses them.
The reset of the client at `clientResetIntervalMs` closes them.

## Observability

The sender emits no event; the delivery loop emits the events from the
results. One `debug` log line for each request, `Webhook request finished`, has
the lane, the delivery ID, the status (the status code, the category or the
reason code), the result kind and the request time. It never holds the URL, a
secret, a signature or a body.

## Tests

The suite `http` has unit tests with `MockHttpClient` and functional tests
against a real HTTP server, `tools/webhook-test-server/server.mjs` (Node.js,
built-in modules only). The functional tests read its URL from
`WEBHOOK_SERVER_URL`: compose runs the server as the service `webhook-server`,
and CI starts it as a background process. The end-to-end test also uses the
PostgreSQL adapter, which is a `require-dev` dependency of this package.
