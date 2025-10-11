# Unit Tests

## Rules
- Localized in Unit folder
- Tests in isolation, no amqp, no database (all transports are in memory)
- No mocks, Fakes are allowed (InMemory implementation etc..)

## Prerequisites
- Symfony/Messenger EventBus object should be created via a custom factory that allows creating pure EventBus without any .yaml files etc.
- EventBus should have the same outbox and amqp transports (only in-memory) with analogous configuration
  - OutboxSerializer should be the same as in the main application
  - EventBus should have the DeduplicationMiddleware registered
  - Serializers and normalizers should be the same as in the main application
- EventBus factory should have factory methods for different scenarios in the future.

## Use cases

#### TestMessage is dispatched to EventBus and serialized to Outbox
- Custom TestMessage is dispatched to EventBus
- TestMessage configured to be routed to Outbox Transport
- Assertions checks if the serialized message in Outbox InMemory transport has correct format
  - MessageId stamp with uuid.v7
  - type the header with the replaced name (from FQN to MessageName)
  - Correct JSON format of the message