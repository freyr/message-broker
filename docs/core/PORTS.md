# Ports

The core talks to storage and to the transport through ports (interfaces).
The core has no `PDO` and no `PhpAmqpLib` in its code. An adapter package
implements the ports. This table lists each port, its purpose and the classes
that implement it. A new adapter (for example MySQL or SQS) implements the same
ports and extends the contract test cases of the test kit (see
[TESTING.md](TESTING.md)).

| Port | Purpose | Implemented by |
|---|---|---|
| `Outbox\OutboxStore` | Insert outbox rows. Claim due rows of one lane and apply the outcome. | `PostgreSqlOutboxStore` (PostgreSQL adapter); `InMemoryOutboxStore` (test kit) |
| `Outbox\OutboxPublisher` | Send claimed rows to the transport and report the refused rows. | `AmqpMessagePublisher` (AMQP adapter); `FakeOutboxPublisher` (test kit) |
| `Outbox\OutboxRetention` | Create the upcoming outbox partitions and drop the expired ones. | `PostgreSqlOutboxRetention` (PostgreSQL adapter) |
| `Storage\SchemaInstaller` | Create the tables, or return their DDL. | `PostgreSqlSchemaInstaller` (PostgreSQL adapter) |
| `Storage\Transaction` | Begin, commit and roll back the consumer transaction. | `PdoTransaction` (PostgreSQL adapter); `InMemoryTransaction` (test kit) |
| `Consumer\DeduplicationStore` | Record that a consumer processed a message. Prune old entries. | `PostgreSqlDeduplicationStore` (PostgreSQL adapter); `InMemoryDeduplicationStore` (test kit) |
| `DeadLetter\DeadLetterStore` | Store, find, list, mark as replayed and purge dead letters. | `PostgreSqlDeadLetterStore` (PostgreSQL adapter); `InMemoryDeadLetterStore` (test kit) |
| `Consumer\DeliveryControl` | Ack one delivery, or schedule its retry. | `AmqpDeliveryControl` (AMQP adapter); `RecordingDeliveryControl` (test kit) |
| `Consumer\MessageDispatcher` | Run the handler of an incoming message. | `CallableDispatcher` (core); your own adapter to a command bus |
| `Retry\RetryPolicy` | Decide to retry, dead-letter or discard a failed delivery. | `ExponentialRetryPolicy` and `RetryPolicyMap` (core); your own policy |
| `Serializer\WireFormat` | Encode the payload to the bytes of the `body` column. | `JsonWireFormat` (core) |
| `Serializer\Deserializer` | Decode a delivery to an `IncomingMessage`. | `JsonDeserializer` (core) |
| `Observability\BrokerEvents` | Receive the lifecycle events (see [PRODUCTION.md](PRODUCTION.md), *Observability*). | Your own class |
| `ErrorHandler` | Receive each handled failure with its context. | Your own class |
