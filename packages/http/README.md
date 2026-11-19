# freyr/message-broker-http

This package is the HTTP webhook sender of the Freyr Message Broker library.
It is not published separately yet. Today the repository
`freyr/message-broker` holds its code in `src/adapter/http/`.

The adapter holds `HttpDeliverySender`, which implements the port
`Webhook\DeliverySender` of the core with Symfony HttpClient. It sends the
deliveries of one claim in parallel as `POST` requests, signs each request as
the Standard Webhooks specification says, follows no redirect, and refuses a
destination in a private network by default.

## Requirements

- PHP 8.4 or later
- `freyr/message-broker` ^0.12 (the core)
- `symfony/http-client` ^8.1 and `symfony/http-foundation` ^8.1
- `psr/log` ^3.0
- A storage adapter for the webhook deliveries, for example
  `freyr/message-broker-postgresql`

## Install

After the split:

```bash
composer require freyr/message-broker-http
```

Today, `composer require freyr/message-broker` installs this code with the
core, and you add `symfony/http-client` and `symfony/http-foundation`
yourself.

## Minimal wiring

```php
use Freyr\MessageBroker\Adapter\Http\HttpDeliverySender;
use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;

$sender = new HttpDeliverySender(new HttpSenderConfig(), logger: $logger);
// Give $sender to Webhook\DeliveryLoop of the core.
```

## Documents

- [Webhooks](../../docs/core/WEBHOOKS.md): the model, the ports and the delivery loop of the core
