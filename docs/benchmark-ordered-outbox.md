# Ordered Outbox Query Benchmark

Performance benchmark for the `OrderedOutboxTransport` head-of-line query against MySQL 8.0 in Docker.

**Date:** 2026-03-08
**MySQL:** 8.0 (Docker, default `innodb_buffer_pool_size` 128MB)
**Table:** 100,000 rows, ~500-byte body per row
**Query:** `SELECT m.* FROM messenger_outbox m WHERE m.id IN (SELECT MIN(sub.id) ... GROUP BY sub.partition_key) LIMIT 1`

## Results

All timings are averages over 20 iterations.

| Partitions | Delivered | Avg (ms) | Min (ms) | Max (ms) | P99 (ms) | Result |
|------------|-----------|----------|----------|----------|----------|--------|
| 10         | 50%       | 29.09    | 27.80    | 35.04    | 35.04    | PASS   |
| 100        | 50%       | 31.49    | 28.69    | 47.40    | 47.40    | PASS   |
| 1,000      | 0%        | 28.72    | 27.19    | 33.22    | 33.22    | PASS   |
| **1,000**  | **50%**   | **29.48**| **28.25**| **31.69**| **31.69**| **PASS** |
| 1,000      | 90%       | 31.70    | 29.85    | 39.58    | 39.58    | PASS   |
| 10,000     | 50%       | 30.19    | 28.91    | 31.22    | 31.22    | PASS   |
| 10,000     | 90%       | 31.31    | 30.18    | 32.35    | 32.35    | PASS   |

**Primary target:** `< 50ms` at 1,000 partitions, 50% delivered — **PASS (29.48ms)**

**Stretch target:** `< 50ms` at 10,000 partitions, 90% delivered — **PASS (31.31ms)**

## Query Plan Analysis

The `EXPLAIN ANALYZE` output confirms efficient index usage across all scenarios:

```
-> Covering index lookup on sub using idx_outbox_partition_order
   (queue_name='ordered_outbox')
   (cost=1251 rows=50000) (actual time=0.03..17.2 rows=100000 loops=1)
```

Key observations:

- **Index used:** `idx_outbox_partition_order (queue_name, partition_key, available_at, delivered_at, id)` — the covering index handles the subquery entirely
- **No filesort:** The `GROUP BY partition_key` uses the index ordering
- **No temporary table:** MySQL materializes the subquery results but does not create a user-visible temporary table
- **Consistent performance:** Partition count (10 to 10,000) and delivered ratio (0% to 90%) have minimal impact on query time
- **OR condition acceptable:** The `(delivered_at IS NULL OR delivered_at < ?)` condition does not prevent index usage — MySQL scans the full index range for the queue but the covering index makes this fast

### Why Performance Is Stable

The query time is dominated by the index scan of 100k rows in the subquery, regardless of partition count or delivered ratio. The `GROUP BY` aggregation and materialization add minimal overhead. The covering index avoids any table lookups for the subquery phase.

## Index Design

The benchmark used the indexes created by `OrderedOutboxTransport::setup()`:

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_outbox_partition_order` | `(queue_name, partition_key, available_at, delivered_at, id)` | Covers the head-of-line subquery |
| `idx_outbox_available` | `(queue_name, available_at, delivered_at, id)` | Used by the standard `doctrine://` transport |

## Environment

- MySQL 8.0 in Docker with default configuration
- `innodb_buffer_pool_size`: 128MB (Docker default)
- Body size: ~500 bytes per row (inline in InnoDB page)
- Benchmark tool: `playground:benchmark:seed` + `playground:benchmark:query`

## Reproducing

```bash
cd message-broker-playground

# Seed
docker compose run --rm php bin/console playground:benchmark:seed \
    --rows=100000 --partitions=1000 --delivered-ratio=0.5 --truncate

# Benchmark
docker compose run --rm php bin/console playground:benchmark:query --iterations=20
```
