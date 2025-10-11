<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Freyr\MessageBroker\Serializer\MessageNameSerializer;
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
        // Create serializer with message type mappings
        $serializer = new MessageNameSerializer($messageTypes);

        // Create in-memory transports
        $outboxTransport = new InMemoryTransport($serializer);
        $amqpTransport = new InMemoryTransport($serializer);

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
            serializer: $serializer,
        );
    }

    /**
     * Create MessageBus for testing inbox consumption with deduplication.
     *
     * Configuration:
     * - AMQP transport with MessageNameSerializer
     * - DeduplicationMiddleware (in-memory store)
     * - Routes messages to handlers
     *
     * @param array<string, class-string> $messageTypes Message name to class mapping
     * @param array<class-string, callable> $handlers Message class to handler mapping
     */
    public static function createForInboxTesting(array $messageTypes = [], array $handlers = []): EventBusTestContext
    {
        // Create serializer
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

        // Create middleware chain
        $middleware = [
            new SendMessageMiddleware(new SendersLocator([], $transportContainer)),
            // TODO: Add DeduplicationMiddleware here when implementing inbox tests
            new HandleMessageMiddleware($handlersLocator),
        ];

        // Create message bus
        $bus = new MessageBus($middleware);

        return new EventBusTestContext(
            bus: $bus,
            outboxTransport: $outboxTransport,
            amqpTransport: $amqpTransport,
            serializer: $serializer,
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
