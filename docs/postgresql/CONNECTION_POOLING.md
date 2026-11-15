# Connection pooling (pgbouncer)

All components are safe behind a transaction-mode pooler. No component holds
a session-scoped lock: the relay claim and the consumer deduplication each run
inside one transaction, and the producer joins your own transaction. Any pool
mode works, including `pool_mode = transaction`.
