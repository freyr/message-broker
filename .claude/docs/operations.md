# Operations Guide

## Running Workers

### Outbox Worker (Publishing)

```bash
php bin/console messenger:consume outbox -vv
```

### Inbox Consumer (AMQP to Handlers)

**Prerequisites**: Queue must already exist in RabbitMQ with proper bindings configured.

```bash
# Example: consume from amqp_orders transport
php bin/console messenger:consume amqp_orders -vv
```

Messages are automatically:
1. Deserialized by InboxSerializer into typed PHP objects
2. Deduplicated by DeduplicationMiddleware
3. Routed to handlers based on PHP class

## Testing

### Running Tests

```bash
# All tests
docker compose run --rm php vendor/bin/phpunit --testdox

# Specific test suite
docker compose run --rm php vendor/bin/phpunit tests/Functional/

# Single test
docker compose run --rm php vendor/bin/phpunit tests/Functional/OutboxFlowTest.php
```

### Testing Deduplication

```bash
# Send 3 identical messages
php bin/console fsm:test-inbox-dedup

# Check database - should have only 1 row
php bin/console dbal:run-sql "SELECT HEX(id), queue_name FROM messenger_messages WHERE queue_name='inbox'"
```

## Monitoring & Maintenance

### Queue Statistics

```bash
# View queue statistics
php bin/console messenger:stats

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

### Cleanup

```bash
# Clean up old outbox messages (older than 7 days) - OPTIONAL
php bin/console messenger:cleanup-outbox --days=7 --batch-size=1000

# Note: This is optional housekeeping. Symfony marks messages as delivered but doesn't
# auto-delete them. Run periodically (cron/scheduler) to prevent messenger_outbox growth.
```

## Production Deployment

### Docker Compose Example

```yaml
services:
  # Process outbox database and publish to AMQP
  worker-outbox:
    image: your-app:latest
    command: php bin/console messenger:consume outbox --time-limit=3600
    restart: always
    deploy:
      replicas: 2

  # Consume from AMQP and process with handlers
  worker-amqp-orders:
    image: your-app:latest
    command: php bin/console messenger:consume amqp_orders --time-limit=3600
    restart: always
    deploy:
      replicas: 3
```

### Worker Configuration

Deploy workers using systemd, supervisor, or Docker with:
- Time limits (e.g., `--time-limit=3600`)
- Automatic restart on failure
- Multiple replicas for high availability

### Metrics to Track

- Outbox queue depth (`messenger:stats`)
- Inbox processing lag
- Failed message count
- Worker health/uptime

## Scaling

- Run multiple AMQP consumers: one per queue (e.g., `messenger:consume amqp_orders`)
- Run multiple outbox workers: `messenger:consume outbox`
- All workers support horizontal scaling with SKIP LOCKED
