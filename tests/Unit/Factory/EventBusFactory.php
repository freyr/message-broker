<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Freyr\MessageBroker\Inbox\DeduplicationMiddleware;
use Freyr\MessageBroker\Serializer\MessageNameSerializer;
use Freyr\MessageBroker\Tests\Unit\Store\DeduplicationInMemoryStore;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/**
 * Factory for creating configured MessageBus instances for unit testing.
 *
 * Creates a complete Messenger setup programmatically without YAML configuration:
 * - In-memory transports (outbox, amqp)
 * - MessageNameSerializer with custom normalizers
 * - Routing configuration
 * - Middleware chain
 */
final class EventBusFactory
{
    /**
     * Create MessageBus for testing outbox serialization.
     *
     * Configuration:
     * - Outbox transport with MessageNameSerializer
     * - Routes messages based on FQN configuration
     * - Optional handlers for consumption testing
     *
     * @param array<string, class-string> $messageTypes Message name to class mapping for deserialization
     * @param array<class-string, array<string>> $routing FQN to transport names mapping (e.g., [TestMessage::class => ['outbox']])
     * @param array<class-string, array<callable>> $handlers Message class to handler mapping (e.g., [TestMessage::class => [callable]])
     */
    public static function createForOutboxTesting(array $messageTypes = [], array $routing = [], array $handlers = []): EventBusTestContext
    {
        // Create MessageNameSerializer for outbox (semantic names)
        $messageNameSerializer = new MessageNameSerializer($messageTypes);

        // Create standard Symfony serializer for AMQP (FQN in type header)
        $standardSerializer = new \Symfony\Component\Messenger\Transport\Serialization\Serializer();

        // Create in-memory transports with different serializers
        $outboxTransport = new InMemoryTransport($messageNameSerializer);
        $amqpTransport = new InMemoryTransport($standardSerializer);

        // Create transport locator for routing
        $transportContainer = new SimpleContainer([
            'outbox' => $outboxTransport,
            'amqp' => $amqpTransport,
        ]);

        $senderLocator = new SendersLocator($routing, $transportContainer);

        // Create handlers locator
        $handlersLocator = new HandlersLocator($handlers);

        // Create middleware chain
        $middleware = [
            new SendMessageMiddleware($senderLocator),
            new HandleMessageMiddleware($handlersLocator),
        ];

        // Create message bus
        $bus = new MessageBus($middleware);

        return new EventBusTestContext(
            bus: $bus,
            outboxTransport: $outboxTransport,
            amqpTransport: $amqpTransport,
            serializer: $messageNameSerializer,
        );
    }

    /**
     * Create MessageBus for testing complete inbox flow with deduplication.
     *
     * Configuration:
     * - Outbox transport with MessageNameSerializer (for publishing)
     * - AMQP transport with MessageNameSerializer (for consuming)
     * - DeduplicationMiddleware (in-memory store)
     * - Routes messages to handlers
     *
     * This setup allows testing the complete flow:
     * 1. Publish to outbox → OutboxToAmqpBridge → AMQP
     * 2. Consume from AMQP → MessageNameSerializer → DeduplicationMiddleware → Handler
     *
     * @param array<string, class-string> $messageTypes Message name to class mapping
     * @param array<class-string, array<callable>> $handlers Message class to handler mapping
     * @param array<class-string, array<string>> $routing FQN to transport names mapping
     */
    public static function createForInboxFlowTesting(array $messageTypes = [], array $handlers = [], array $routing = []): InboxFlowTestContext
    {
        // Create MessageNameSerializer for both outbox and AMQP
        $serializer = new MessageNameSerializer($messageTypes);

        // Create in-memory transports
        $outboxTransport = new InMemoryTransport($serializer);
        $amqpTransport = new InMemoryTransport($serializer);

        // Create handlers locator
        $handlersLocator = new HandlersLocator($handlers);

        // Create transport container
        $transportContainer = new SimpleContainer([
            'outbox' => $outboxTransport,
            'amqp' => $amqpTransport,
        ]);

        // Create deduplication store (in-memory for testing)
        $deduplicationStore = new DeduplicationInMemoryStore();

        // Create deduplication middleware with store
        $deduplicationMiddleware = new DeduplicationMiddleware($deduplicationStore);

        // Create middleware chain
        // DeduplicationMiddleware runs BEFORE handlers (like in production)
        $middleware = [
            new SendMessageMiddleware(new SendersLocator($routing, $transportContainer)),
            $deduplicationMiddleware,
            new HandleMessageMiddleware($handlersLocator),
        ];

        // Create message bus
        $bus = new MessageBus($middleware);

        return new InboxFlowTestContext(
            bus: $bus,
            outboxTransport: $outboxTransport,
            amqpTransport: $amqpTransport,
            serializer: $serializer,
            deduplicationStore: $deduplicationStore,
        );
    }
}

/**
 * Test context containing MessageBus and related components for assertions.
 */
final readonly class EventBusTestContext
{
    public function __construct(
        public MessageBusInterface $bus,
        public InMemoryTransport $outboxTransport,
        public InMemoryTransport $amqpTransport,
        public MessageNameSerializer $serializer,
    ) {
    }
}

/**
 * Test context for inbox flow testing with deduplication.
 */
final readonly class InboxFlowTestContext
{
    public function __construct(
        public MessageBusInterface $bus,
        public InMemoryTransport $outboxTransport,
        public InMemoryTransport $amqpTransport,
        public MessageNameSerializer $serializer,
        public DeduplicationInMemoryStore $deduplicationStore,
    ) {
    }
}
