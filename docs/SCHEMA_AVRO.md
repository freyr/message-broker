# Schema and Avro

The wire format is a single, global, setup-time choice for the whole outbox
(`message-broker:setup:schema --format=json|avro`) — there is no per-lane or
per-message mix. This document covers when to reach for Avro, how schemas
are mapped and registered, and how compatibility is governed.

## Choosing JSON vs Avro

> JSON is schemaless by design — `JsonWireFormat` validates only that the
> payload is JSON-encodable, not its shape. Avro validates the payload
> against the schema at produce time (the encode is the poison check). Use
> Avro if you need produce-time contract enforcement.

Both formats encode at produce time, inside your database transaction: a
payload that fails to encode throws before anything commits. The difference
is what "fails to encode" means. With JSON, almost nothing fails — any
PHP array that `json_encode` accepts is valid, regardless of shape. With
Avro, `AvroWireFormat::encode()` writes the payload against a specific
`AvroSchema`; a payload with a missing required field, a wrong type, or an
extra field the schema doesn't allow throws `AvroIOTypeException` at that
point. If your producers and consumers are written by different teams, or
you need a hard contract enforced before a bad payload ever reaches the
outbox, use Avro.

## FileSchemaStore mapping

`FileSchemaStore` is the committed-schema source of truth: hand-written
payload-record `.avsc` files shipped in your application repository, mapped
explicitly per `message_name` (the subject). It only reads local files — it
never talks to the network.

```php
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;

$schemas = new FileSchemaStore([
    'order.placed' => __DIR__.'/schemas/order_placed.avsc',
]);
```

```json
{
  "type": "record",
  "name": "OrderPlaced",
  "namespace": "app.orders",
  "fields": [
    {"name": "order_id", "type": "string"},
    {"name": "total_cents", "type": "long"}
  ]
}
```

Wire `AvroWireFormat` (producer) and `AvroDeserializer` (consumer) against a
`SchemaRegistry` — `HttpSchemaRegistry` is the shipped Confluent-compatible
client, backed by a PSR-6 cache pool so a lookup only hits the network once
per subject/schema id:

```php
use Freyr\MessageBroker\Cache\ArrayCachePool;
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistry;

$schemas = new FileSchemaStore([
    'order.placed' => __DIR__.'/schemas/order_placed.avsc',
]);
$registry = new HttpSchemaRegistry('http://schema-registry.internal:8081', new ArrayCachePool());

$wireFormat = new AvroWireFormat($schemas, $registry);   // producer side
$deserializer = new AvroDeserializer($registry);          // consumer side
```

`ArrayCachePool` is per-process and does not survive a restart. Use
`FileCachePool` (filesystem, one host) or `RedisCachePool` (shared across
hosts and processes) instead — schema ids and schema JSON are immutable
once registered, so cached entries carry no TTL, and a shared cache removes
cold-start registry hits after every producer/relay/consumer restart.

## Registering schemas

Registration is **out-of-band** — a CI step, never the runtime.
`message-broker:schema:register` drives off the same `FileSchemaStore` map
the producer uses, so CI registers exactly the subjects produce will look
up:

```bash
php bin/console message-broker:schema:register
php bin/console message-broker:schema:register --subject=order.placed
php bin/console message-broker:schema:register --dry-run
php bin/console message-broker:schema:register --compatibility=FULL
```

Construct the command with the same `FileSchemaStore` map and an
`HttpSchemaRegistrar` (the write-side client, separate from the read-side
`HttpSchemaRegistry` used by the wire format):

```php
use Freyr\MessageBroker\Console\SchemaRegisterCommand;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use Freyr\MessageBroker\Serializer\Avro\HttpSchemaRegistrar;

$command = new SchemaRegisterCommand(
    new FileSchemaStore(['order.placed' => __DIR__.'/schemas/order_placed.avsc']),
    new HttpSchemaRegistrar('http://schema-registry.internal:8081'),
);
$application->add($command);
```

Re-registering an identical schema is idempotent — the registry returns the
same schema id.

## Compatibility governance

`message-broker:schema:compatibility` sets or shows a subject's registry
compatibility level, independent of registering a new schema version:

```bash
php bin/console message-broker:schema:compatibility --subject=order.placed --level=FULL
php bin/console message-broker:schema:compatibility --subject=order.placed
```

Valid levels: `BACKWARD`, `BACKWARD_TRANSITIVE`, `FORWARD`,
`FORWARD_TRANSITIVE`, `FULL`, `FULL_TRANSITIVE`, `NONE`. Omitting `--level`
prints the subject's current level, or `(registry default)` if no per-subject
override is set.

An incompatible schema surfaces as a **distinct error** from a registry
outage, so callers (and CI) can branch on which one happened:

- **`IncompatibleSchema`** — the registry answered HTTP 409: the schema is
  well-formed but violates the subject's compatibility policy. This is
  **permanent**; retrying will not help. A CI registration gate should fail
  the build on this error.
- **`RegistryUnavailable`** — a network failure, a 5xx response, or an
  unparseable response. This is **transient**; at produce time the exception
  throws inside your transaction — nothing commits; on the consumer side it
  propagates so the delivery is redelivered rather than dead-lettered.
