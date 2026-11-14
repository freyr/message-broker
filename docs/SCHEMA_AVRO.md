# Schema and Avro

The wire format is a single, global, setup-time choice for the whole outbox
(`message-broker:setup:schema --format=json|avro`) — there is no per-lane or
per-message mix. This document covers when to reach for Avro, how committed
schemas are laid out and wired, and how compatibility is governed — all
**registry-free**: there is no Confluent Schema Registry at runtime.

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

## Committed schemas: the versioned layout

Schemas are hand-written payload-record `.avsc` files committed to your
application repository — the source of truth. Because a consumer must be able
to decode a message written by an **older, still-deployed producer** during a
rolling deploy, every schema version is kept as a **distinct, immutable file**:

```
schemas/
  order.placed/
    v1.avsc
    v2.avsc      # the current version
```

The convention is a governance contract, not a suggestion: **never edit a
version file in place.** A schema change is a new `v<N>.avsc` file; the prior
versions stay committed so their bytes (and their derived ids) remain
resolvable. Editing in place would delete the old version and dead-letter any
in-flight message written with it.

Two views read this layout:

- `FileSchemaStore` maps each `message_name` (subject) to its **current**
  version. It is the producer's encode source and the consumer's reader schema.
- `FileSchemaRegistry` indexes **every** committed version by its derived id,
  so a consumer can fetch the exact writer schema for any frame it receives.

## Wiring the producer and consumer

```php
use Freyr\MessageBroker\Serializer\Avro\AvroDeserializer;
use Freyr\MessageBroker\Serializer\Avro\AvroWireFormat;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaRegistry;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;

// Current schema per subject — producer encode source and consumer reader schema.
$currentSchemas = new FileSchemaStore([
    'order.placed' => __DIR__.'/schemas/order.placed/v2.avsc',
]);

// Every committed version, indexed by derived id — the registry-free resolver.
$registry = new FileSchemaRegistry(glob(__DIR__.'/schemas/*/v*.avsc'));

$wireFormat   = new AvroWireFormat($currentSchemas, $registry);   // producer side
$deserializer = new AvroDeserializer($registry, $currentSchemas); // consumer side
```

On produce, the id is **derived** from the current committed schema and stamped
into the wire frame. On consume, the writer schema is fetched by that id and
resolved against this consumer's own reader schema (its current version), so a
message written with `v1` decodes cleanly against a `v2` reader — new fields are
filled from the reader schema's defaults. This reader/writer resolution is
Avro's forward-compatibility mechanism.

Neither side touches the network: resolution uses only the committed schemas
shipped in the image. No schema registry is reachable or required in production.

## Schema ids are derived, not assigned

The wire frame is unchanged (Confluent-style: a magic byte + a 4-byte id +
the Avro payload), but the id is a `crc32` of the committed schema bytes masked
to the frame's 31-bit range (`DerivedSchemaId`) — not a registry-assigned
number. Two byte-identical committed files derive the same id; two genuinely
different schemas must derive different ids. That uniqueness is guarded both at
`FileSchemaRegistry` construction (it throws on a collision) and in CI (below),
so a collision is a build-time error, never a silent runtime mis-resolution.

## Validating the committed set (CI)

`message-broker:schema:validate` is a pure-PHP CI check (no registry, no JVM). It
asserts that every committed schema derives a unique id and that every current
producer schema is present in the committed all-versions set:

```php
use Freyr\MessageBroker\Console\SchemaValidateCommand;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;

$command = new SchemaValidateCommand(
    new FileSchemaStore(['order.placed' => __DIR__.'/schemas/order.placed/v2.avsc']),
    glob(__DIR__.'/schemas/*/v*.avsc'),
);
$application->add($command);
```

It exits non-zero (failing the build) on a derived-id collision, an unparseable
schema, or a current schema missing from the committed set.

## Compatibility governance (CI, offline)

Backward/forward compatibility **between versions** is enforced by a
reference-correct, offline Avro compatibility checker — Confluent's
`schema-registry-maven-plugin` `test-local-compatibility` goal — run as a CI
gate against the committed files with **no running registry**. It ships as
`ci/schema-compat/` plus a make target:

```bash
make schema-compat
```

The gate compares each subject's current schema against its retained prior
versions at a configured level (e.g. `FULL_TRANSITIVE`) and fails the build on
an incompatible change. The JVM/Maven toolchain lives only in this CI gate — the
library and your application stay pure-PHP at runtime.

Maintenance contract: when a subject gains a new version, point the gate's
`schemas` at the new current file and add every prior version to
`previousSchemaPaths` (`ci/schema-compat/pom.xml`). Prior versions must stay
committed, or the comparison has nothing to check against.
