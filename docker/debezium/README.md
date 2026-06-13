# Debezium spike (optional)

An **alternative** relay: Debezium reads the MySQL binlog and publishes the outbox
rows to Kafka, so there is no PHP process in the publish path. This is a spike —
a decision-grade artifact, **not** a production-hardened, CI-tested code path
(design §9). The analytical decisions are recorded in the vault research note
`docs/research/2026-06-13-debezium-spike.md`; the connector config here is the
spike-validated config. Only end-to-end byte-for-byte frame fidelity remains as
an empirical check (Task 16 step 3).

## Hard invariant: one relay per table

Run **either** the PHP relay **or** Debezium against `outbox_messages`, never both
(hard cutover). Binlog capture is independent of the PHP relay's poll-and-delete,
so running both guarantees double-publish. Per-lane ordering / partitioner parity
(lane→topic, `message_key`→partition) is a slice-5 Kafka concern, not this spike.

## Why this config

- `value.converter` is `io.debezium.converters.BinaryDataConverter` (NOT
  `ByteArrayConverter`, which is the MongoDB-outbox-SMT converter). With its
  delegate and the default `binary.handling.mode=bytes`, the Confluent-framed
  Avro `body` (LONGBLOB) passes through byte-for-byte. Header (not envelope)
  placement; do not base64/hex re-encode the frame.
- Individual `x-message-*` headers come from **columns** via stock EventRouter
  `additional.placement` — `message_name` is a real column (mirrored from
  `metadata.message_name`), so `x-message-id` / `x-message-name` / `x-created-at`
  map directly, no custom SMT. This matches the PHP relay's headers.
- EventRouter drops `op='d'` and tombstones unconditionally — capture-then-delete
  is safe with no `skipped.operations` / `tombstones.on.delete` config.

## Running the spike

CDC requires the `mysql` service to run with ROW-format binlog. The default
`mysql:8.0` service does not set it, so start it once with the override:

```bash
# 1. MySQL with binlog (one-off; do NOT leave this on the default stack):
docker compose run -d --name mb-mysql-binlog -p 3306:3306 \
  mysql --server-id=1 --log-bin=mysql-bin --binlog-format=ROW
#    (or add these flags to the mysql service `command:` only while spiking)

# 2. Bring up the Debezium profile (adds `connect`; `kafka` is already in the
#    default stack as the Schema Registry's backend):
docker compose --profile debezium up -d

# 3. Create the outbox table in the avro format and register the connector:
docker compose run --rm php vendor/bin/... setup:schema --format=avro   # via your console entrypoint
curl -s -X POST -H 'Content-Type: application/json' \
  --data @docker/debezium/order-outbox-connector.json http://localhost:8083/connectors

# 4. Produce an Avro row, then consume the routed Kafka topic and feed the value
#    bytes + the x-message-* record headers to AvroDeserializer — it must decode
#    identically to the PHP-relay path (exact Confluent frame fidelity).
```

Empirical item still to confirm: that `x-created-at` arrives as an epoch-ms int
(matching `MetadataHeader::parse`); Debezium temporal encoding depends on
`time.precision.mode` and may need a cast SMT. Record findings + the final
working connector config back in the research note.
