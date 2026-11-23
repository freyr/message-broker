# Changelog

All notable changes to `freyr/message-broker-storage-postgresql` are documented in this file. The package is
developed in [freyr/message-broker](https://github.com/freyr/message-broker),
folder `storage/postgresql/`; its tags there are `storage-postgresql-v<version>`.

## [Unreleased]

### Added

- The first release of `freyr/message-broker-storage-postgresql`: the PostgreSQL storage adapter of the Freyr Message Broker
  library as its own package, with its own version. Until `freyr/message-broker`
  0.14.x, the package `freyr/message-broker` held this code. The namespace is
  `Freyr\MessageBroker\Storage\PostgreSql\` in place of
  `Freyr\MessageBroker\Adapter\PostgreSql\`; the class names do not change. The
  package requires `freyr/message-broker` ^0.15 and provides `freyr/message-broker-storage-implementation`.
  See [UPGRADE.md](https://github.com/freyr/message-broker/blob/main/UPGRADE.md).
