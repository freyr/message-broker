# Deduplication

## Requirements
- every message has message_id header with uuid.v7 string
- every message has 'type' header that contains message_name (string separated by dots: domain.subdomain.event)
  - This is assured via outbox process.

## Flow
- Message is ingested from AMQP
- Transaction middleware opens a transaction
- Deduplication middleware checks deduplication_log and drops message if necessary
- If no deduplication_log entry is found, message is saved to deduplication_log and passed to handler
- Handler processes message
- Transaction is committed
- Both operations are commited or rollback at the same time
  - with rollback, deduplication_log drops message_id
  - with commit, deduplication_log saves message_id