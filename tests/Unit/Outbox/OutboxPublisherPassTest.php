<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Outbox;

use Freyr\MessageBroker\DependencyInjection\Compiler\OutboxPublisherPass;
use Freyr\MessageBroker\Outbox\OutboxPublisherInterface;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Envelope;

/**
 * Unit test for OutboxPublisherPass compiler pass.
 */
final class OutboxPublisherPassTest extends TestCase
{
    public function testCollectsTaggedPublishersIntoServiceLocator(): void
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        $publisherDef = new Definition(TestPublisher::class);
        $publisherDef->addTag('message_broker.outbox_publisher', ['transport' => 'outbox']);
        $container->setDefinition('test.publisher', $publisherDef);

        $pass = new OutboxPublisherPass();
        $pass->process($container);

        // Verify the publisher locator argument was set (it's a Reference to a generated service locator)
        $locatorArg = $middlewareDef->getArgument('$publisherLocator');
        $this->assertInstanceOf(Reference::class, $locatorArg);
    }

    public function testThrowsOnMissingTransportAttribute(): void
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        $publisherDef = new Definition(TestPublisher::class);
        $publisherDef->addTag('message_broker.outbox_publisher');
        $container->setDefinition('test.publisher', $publisherDef);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must define "transport" attribute/');

        $pass = new OutboxPublisherPass();
        $pass->process($container);
    }

    public function testThrowsOnDuplicateTransportName(): void
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        $publisher1 = new Definition(TestPublisher::class);
        $publisher1->addTag('message_broker.outbox_publisher', ['transport' => 'outbox']);
        $container->setDefinition('test.publisher1', $publisher1);

        $publisher2 = new Definition(TestPublisher::class);
        $publisher2->addTag('message_broker.outbox_publisher', ['transport' => 'outbox']);
        $container->setDefinition('test.publisher2', $publisher2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate outbox publisher for transport "outbox"/');

        $pass = new OutboxPublisherPass();
        $pass->process($container);
    }

    public function testThrowsWhenServiceDoesNotImplementInterface(): void
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        $publisherDef = new Definition(\stdClass::class);
        $publisherDef->addTag('message_broker.outbox_publisher', ['transport' => 'outbox']);
        $container->setDefinition('test.bad_publisher', $publisherDef);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        $pass = new OutboxPublisherPass();
        $pass->process($container);
    }

    public function testEarlyReturnWhenMiddlewareNotDefined(): void
    {
        $container = new ContainerBuilder();

        $pass = new OutboxPublisherPass();
        // Should not throw — and should not define the middleware service
        $pass->process($container);

        $this->assertFalse($container->hasDefinition(OutboxPublishingMiddleware::class));
    }

    public function testHandlesNoPublishersGracefully(): void
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        $pass = new OutboxPublisherPass();
        $pass->process($container);

        // Should still set the locator argument (empty service locator)
        $locatorArg = $middlewareDef->getArgument('$publisherLocator');
        $this->assertInstanceOf(Reference::class, $locatorArg);
    }
}

/**
 * @internal Test double implementing OutboxPublisherInterface
 */
final class TestPublisher implements OutboxPublisherInterface
{
    public function publish(Envelope $envelope): void {}
}
