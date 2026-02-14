<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Freyr\MessageBroker\Serializer\InboxSerializer;
use Freyr\MessageBroker\Serializer\WireFormatSerializer;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use Symfony\Component\Messenger\MessageBusInterface;

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
