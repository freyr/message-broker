<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Freyr\MessageBroker\Amqp\AmqpOutboxPublisher;
use Freyr\MessageBroker\Amqp\Routing\DefaultAmqpRoutingStrategy;
use Freyr\MessageBroker\Inbox\DeduplicationMiddleware;
use Freyr\MessageBroker\Outbox\MessageIdStampMiddleware;
use Freyr\MessageBroker\Outbox\MessageNameStampMiddleware;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use Freyr\MessageBroker\Serializer\InboxSerializer;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use Freyr\MessageBroker\Serializer\WireFormatSerializer;
use Freyr\MessageBroker\Tests\Unit\Store\DeduplicationInMemoryStore;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Factory for creating configured MessageBus instances for unit testing.
 *
 * Creates a complete Messenger setup programmatically without YAML configuration:
 * - In-memory transports (outbox with native serialiser, amqp with WireFormatSerializer)
 * - WireFormatSerializer/InboxSerializer with custom normalizers
 * - Routing configuration
 * - Middleware chain (MessageIdStampMiddleware, MessageNameStampMiddleware, etc.)
 */
final class EventBusFactory
{
    /**
     * Create MessageBus for testing outbox serialization.
     *
     * Configuration:
     * - Outbox transport with native serialiser (FQN in type header)
     * - Routes messages based on FQN configuration
     * - Optional handlers for consumption testing
     *
     * @param array<string, class-string> $messageTypes Message name to class mapping for deserialization
     * @param array<string, list<string>> $routing FQN to transport names mapping (e.g., [TestMessage::class => ['outbox']])
     * @param array<class-string, array<callable>> $handlers Message class to handler mapping (e.g., [TestMessage::class => [callable]])
     */
    public static function createForOutboxTesting(
        array $messageTypes = [],
        array $routing = [],
        array $handlers = [],
    ): EventBusTestContext {
        $symfonySerializer = self::createSymfonySerializer();
        $nativeSerializer = new \Symfony\Component\Messenger\Transport\Serialization\Serializer($symfonySerializer);
        $wireFormatSerializer = new WireFormatSerializer($symfonySerializer);
        $inboxSerializer = new InboxSerializer($symfonySerializer, $messageTypes);

        // Create in-memory transports:
        // - Outbox uses native serialiser (FQN in type header — internal storage)
        // - AMQP uses InboxSerializer for consumption testing
        $outboxTransport = new InMemoryTransport($nativeSerializer);
        $amqpTransport = new InMemoryTransport($inboxSerializer);

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
            new MessageIdStampMiddleware(),
            new MessageNameStampMiddleware(),
            new SendMessageMiddleware($senderLocator),
            new HandleMessageMiddleware($handlersLocator),
        ];

        // Create message bus
        $bus = new MessageBus($middleware);

        return new EventBusTestContext(
            bus: $bus,
            outboxTransport: $outboxTransport,
            amqpTransport: $amqpTransport,
            wireFormatSerializer: $wireFormatSerializer,
            inboxSerializer: $inboxSerializer,
        );
    }

    /**
     * Create MessageBus for testing complete inbox flow with deduplication.
     *
     * Transport Architecture (3 transports):
     * 1. Outbox transport (doctrine://outbox) - Stores domain events with native serialiser
     * 2. AMQP publish transport (amqp://publish) - Publisher publishes here with WireFormatSerializer
     * 3. AMQP consume transport (amqp://consume) - Consumers read from here with InboxSerializer
     *
     * Configuration:
     * - Outbox transport with native serialiser (FQN in type header — internal storage)
     * - AMQP publish transport with WireFormatSerializer (for publisher publishing)
     * - AMQP consume transport with InboxSerializer (for consuming external messages)
     * - DeduplicationMiddleware (in-memory store)
     * - Routes messages to handlers
     *
     * Flow:
     * 1. Domain event -> routed to 'outbox' transport
     * 2. OutboxPublishingMiddleware consumes from 'outbox', delegates to AmqpOutboxPublisher
     * 3. AmqpOutboxPublisher publishes to 'amqp_publish'
     * 4. Test simulates external consumer reading from 'amqp_publish' (using InboxSerializer)
     *
     * @param array<string, class-string> $messageTypes Message name to class mapping
     * @param array<class-string, array<callable>> $handlers Message class to handler mapping
     * @param array<string, list<string>> $routing FQN to transport names mapping
     */
    public static function createForInboxFlowTesting(
        array $messageTypes = [],
        array $handlers = [],
        array $routing = [],
    ): InboxFlowTestContext {
        $symfonySerializer = self::createSymfonySerializer();
        $nativeSerializer = new \Symfony\Component\Messenger\Transport\Serialization\Serializer($symfonySerializer);
        $wireFormatSerializer = new WireFormatSerializer($symfonySerializer);
        $inboxSerializer = new InboxSerializer($symfonySerializer, $messageTypes);

        // Create 3 separate transports to avoid mixing concerns:
        // 1. Outbox: Stores domain events (native serialiser — FQN in type header)
        $outboxTransport = new InMemoryTransport($nativeSerializer);

        // 2. AMQP Publish: Publisher publishes here (WireFormatSerializer for encoding)
        $amqpPublishTransport = new InMemoryTransport($wireFormatSerializer);

        // Create handlers locator
        $handlersLocator = new HandlersLocator($handlers);

        // Create transport container
        $transportContainer = new SimpleContainer([
            'outbox' => $outboxTransport,
            'amqp' => $amqpPublishTransport,
        ]);

        // Create deduplication store (in-memory for testing)
        $deduplicationStore = new DeduplicationInMemoryStore();

        // Create deduplication middleware with store
        $deduplicationMiddleware = new DeduplicationMiddleware($deduplicationStore);

        // Create AmqpOutboxPublisher with sender locator
        $amqpPublisher = new AmqpOutboxPublisher(
            senderLocator: new ServiceLocator([
                'amqp' => fn () => $amqpPublishTransport,
            ]),
            routingStrategy: new DefaultAmqpRoutingStrategy(),
            logger: new NullLogger(),
        );

        // Create OutboxPublishingMiddleware with publisher locator
        $publishingMiddleware = new OutboxPublishingMiddleware(
            publisherLocator: new ServiceLocator([
                'outbox' => fn () => $amqpPublisher,
            ]),
            logger: new NullLogger(),
        );

        // Create middleware chain matching production ordering
        $middleware = [
            new MessageIdStampMiddleware(),
            new MessageNameStampMiddleware(),
            new SendMessageMiddleware(new SendersLocator($routing, $transportContainer)),
            $publishingMiddleware,
            $deduplicationMiddleware,
            new HandleMessageMiddleware($handlersLocator),
        ];

        // Create message bus
        $bus = new MessageBus($middleware);

        return new InboxFlowTestContext(
            bus: $bus,
            outboxTransport: $outboxTransport,
            amqpPublishTransport: $amqpPublishTransport,
            wireFormatSerializer: $wireFormatSerializer,
            inboxSerializer: $inboxSerializer,
            deduplicationStore: $deduplicationStore,
        );
    }

    /**
     * Create Symfony Serializer with custom normalizers.
     *
     * Matches production configuration from config/services.yaml:
     * - IdNormalizer for Freyr\Identity\Id
     * - CarbonImmutableNormalizer for Carbon\CarbonImmutable
     * - ObjectNormalizer with propertyTypeExtractor for constructor property promotion
     */
    private static function createSymfonySerializer(): Serializer
    {
        $reflectionExtractor = new ReflectionExtractor();
        $propertyTypeExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$reflectionExtractor],
            [],
            [$reflectionExtractor],
            [$reflectionExtractor]
        );

        return new Serializer(
            [
                new IdNormalizer(),
                new CarbonImmutableNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(null, null, null, $propertyTypeExtractor),
            ],
            [new JsonEncoder()]
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
        public WireFormatSerializer $wireFormatSerializer,
        public InboxSerializer $inboxSerializer,
    ) {}
}

/**
 * Test context for inbox flow testing with deduplication.
 */
final readonly class InboxFlowTestContext
{
    public function __construct(
        public MessageBusInterface $bus,
        public InMemoryTransport $outboxTransport,
        public InMemoryTransport $amqpPublishTransport,
        public WireFormatSerializer $wireFormatSerializer,
        public InboxSerializer $inboxSerializer,
        public DeduplicationInMemoryStore $deduplicationStore,
    ) {}
}
