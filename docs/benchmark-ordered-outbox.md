# Ordered Outbox Query Benchmark

Performance benchmark for the `OrderedOutboxTransport` head-of-line query against MySQL 8.0 in Docker.

**Date:** 2026-03-08
**MySQL:** 8.0 (Docker, default `innodb_buffer_pool_size` 128MB)
**Table:** 100,000 rows, ~500-byte body per row
**Query:** `SELECT ... WHERE m.id IN (SELECT MIN(sub.id) ... GROUP BY sub.partition_key) LIMIT 1 FOR UPDATE SKIP LOCKED`

## Results

All timings are averages over 20 measured iterations (+ 3 warm-up excluded from stats). The timing loop uses `FOR UPDATE SKIP LOCKED` inside a transaction to match production behavior.

In-flight rows have `delivered_at` within the redeliver window and are **excluded** from the head-of-line query, correctly simulating workers holding locks.

| Partitions | In-flight | Avg (ms) | Min (ms) | Max (ms) | Result |
|------------|-----------|----------|----------|----------|--------|
| 10         | 50%       | 24.73    | 23.88    | 25.80    | PASS   |
| 100        | 50%       | 25.50    | 24.15    | 33.33    | PASS   |
| 1,000      | 0%        | 27.81    | 27.28    | 28.93    | PASS   |
| **1,000**  | **50%**   | **26.79**| **25.18**| **30.80**| **PASS** |
| 1,000      | 90%       | 30.64    | 28.97    | 32.67    | PASS   |
| 10,000     | 50%       | 25.25    | 23.82    | 27.44    | PASS   |
| 10,000     | 90%       | 30.44    | 28.74    | 32.80    | PASS   |

**Primary target:** `< 50ms` at 1,000 partitions, 50% in-flight — **PASS (26.79ms)**

**Stretch target:** `< 50ms` at 10,000 partitions, 90% in-flight — **PASS (30.44ms)**

**Notable:** Higher in-flight ratios produce slightly slower queries (0% → 27.81ms vs 90% → 30.64ms) because in-flight rows still require an index scan even though they are excluded by the `delivered_at` filter.

## Query Plan Analysis

The `EXPLAIN ANALYZE` output confirms efficient index usage (shown without `FOR UPDATE SKIP LOCKED` — MySQL does not support EXPLAIN on locking clauses):

```
-> Covering index lookup on sub using idx_outbox_partition_order
   (queue_name='ordered_outbox')
   (cost=1251 rows=50000) (actual time=0.03..24.5 rows=50192 loops=1)
```

Key observations:

- **Index used:** `idx_outbox_partition_order` — the covering index handles the subquery entirely
- **No filesort:** The `GROUP BY partition_key` uses the index ordering
- **No temporary table:** MySQL materializes the subquery results but does not create a user-visible temporary table
- **In-flight filtering works:** With 50% in-flight, the subquery scans ~50k rows (not 100k), confirming that `delivered_at` within the redeliver window correctly excludes in-flight rows

## Index Design

The benchmark used the indexes created by `OrderedOutboxTransport::setup()`:

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_outbox_partition_order` | `(queue_name, partition_key, available_at, delivered_at, id)` | Covers the head-of-line subquery |
| `idx_outbox_available` | `(queue_name, available_at, delivered_at, id)` | Used by the standard `doctrine://` transport |

## Reproducing

```bash
cd message-broker-playground

# Seed
docker compose run --rm php bin/console playground:benchmark:seed \
    --rows=100000 --partitions=1000 --delivered-ratio=0.5 --truncate

# Benchmark
docker compose run --rm php bin/console playground:benchmark:query --iterations=20
```
