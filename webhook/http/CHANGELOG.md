# Changelog

All notable changes to `freyr/message-broker-webhook-http` are documented in this file. The package is
developed in [freyr/message-broker](https://github.com/freyr/message-broker),
folder `webhook/http/`; its tags there are `webhook-http-v<version>`.

## [Unreleased]

### Added

- The first release of `freyr/message-broker-webhook-http`: the HTTP webhook sender of the Freyr Message Broker
  library as its own package, with its own version. Until `freyr/message-broker`
  0.14.x, the package `freyr/message-broker` held this code. The namespace is
  `Freyr\MessageBroker\Webhook\Http\` in place of
  `Freyr\MessageBroker\Adapter\Http\`; the class names do not change. The
  package requires `freyr/message-broker` ^0.15 and provides `freyr/message-broker-webhook-sender-implementation`.
  See [UPGRADE.md](https://github.com/freyr/message-broker/blob/main/UPGRADE.md).
