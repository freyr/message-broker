# Changelog

All notable changes to `freyr/message-broker-ratelimit-redis` are documented in this file. The package is
developed in [freyr/message-broker](https://github.com/freyr/message-broker),
folder `ratelimit/redis/`; its tags there are `ratelimit-redis-v<version>`.

## [Unreleased]

### Added

- The first release of `freyr/message-broker-ratelimit-redis`: the Redis rate limit store of the Freyr Message Broker
  library as its own package, with its own version. Until `freyr/message-broker`
  0.14.x, the package `freyr/message-broker` held this code. The namespace is
  `Freyr\MessageBroker\RateLimit\Redis\` in place of
  `Freyr\MessageBroker\Adapter\Redis\`; the class names do not change. The
  package requires `freyr/message-broker` ^0.15 and provides `freyr/message-broker-ratelimit-implementation`.
  See [UPGRADE.md](https://github.com/freyr/message-broker/blob/main/UPGRADE.md).
