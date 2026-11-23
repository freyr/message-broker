# freyr/message-broker-webhook-http

This package is the HTTP webhook sender of the Freyr Message Broker library,
namespace `Freyr\MessageBroker\Webhook\Http\`.

> The repository `freyr/message-broker-webhook-http` is a read-only copy of the folder
> `webhook/http/` of [freyr/message-broker](https://github.com/freyr/message-broker).
> Development, issues and pull requests are in freyr/message-broker. A
> release has the tag `webhook-http-v<version>` in freyr/message-broker
> and the tag `v<version>` in `freyr/message-broker-webhook-http`.

The adapter holds `HttpDeliverySender`, which implements the port
`Webhook\DeliverySender` of the core with Symfony HttpClient. It sends the
deliveries of one claim in parallel as `POST` requests, signs each request as
the Standard Webhooks specification says, follows no redirect, and refuses a
destination in a private network by default.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.15 (the core)
- `symfony/http-client` ^8.1 and `symfony/http-foundation` ^8.1
- `psr/log` ^3.0
- A storage adapter for the webhook deliveries, for example
  `freyr/message-broker-storage-postgresql`

## Install

```bash
composer require freyr/message-broker-webhook-http
```

## Minimal wiring

```php
use Freyr\MessageBroker\Webhook\Http\HttpDeliverySender;
use Freyr\MessageBroker\Webhook\Http\HttpSenderConfig;

$sender = new HttpDeliverySender(new HttpSenderConfig(), logger: $logger);
// Give $sender to Webhook\DeliveryLoop of the core.
```

## Documents

- [HTTP sender](docs/SENDER.md): the request, the configuration, the results, the timeouts, the DNS allowance and its known limit, the connections
- [Signature](docs/SIGNATURE.md): the `webhook-` headers, the secret, the verification by a receiver
- [Network rules](docs/NETWORK.md): the private network rule, the proxy rule, the `https` rule, no redirects
- [Webhooks](https://github.com/freyr/message-broker/blob/main/docs/WEBHOOKS.md): the model, the ports and the delivery loop of the core
- [Webhook delivery storage](https://github.com/freyr/message-broker/blob/main/storage/postgresql/docs/WEBHOOKS.md): the PostgreSQL store of the deliveries
- [Quickstart](https://github.com/freyr/message-broker/blob/main/docs/QUICKSTART.md#send-webhooks): a webhook example with the three packages

## Tests

The functional tests need a test HTTP server and PostgreSQL; see
[Tests](docs/SENDER.md#tests).
