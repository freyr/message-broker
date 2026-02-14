<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Freyr\MessageBroker\Serializer\InboxSerializer;
use Freyr\MessageBroker\Serializer\WireFormatSerializer;
use Freyr\MessageBroker\Tests\Unit\Store\DeduplicationInMemoryStore;
use Freyr\MessageBroker\Tests\Unit\Store\InMemoryOutboxPublisher;
use Freyr\MessageBroker\Tests\Unit\Transport\InMemoryTransport;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Test context for inbox flow testing with deduplication.
 */
final readonly class InboxFlowTestContext
{
    public function __construct(
        public MessageBusInterface $bus,
        public InMemoryTransport $outboxTransport,
        public InMemoryOutboxPublisher $outboxPublisher,
        public WireFormatSerializer $wireFormatSerializer,
        public InboxSerializer $inboxSerializer,
        public DeduplicationInMemoryStore $deduplicationStore,
    ) {}
}
