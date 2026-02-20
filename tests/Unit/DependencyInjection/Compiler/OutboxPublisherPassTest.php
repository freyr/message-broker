<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DependencyInjection\Compiler;

use Freyr\MessageBroker\DependencyInjection\Compiler\OutboxPublisherPass;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use Freyr\MessageBroker\Tests\Fixtures\TestPublisher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Unit test for OutboxPublisherPass compiler pass.
 *
 * Tests that the compiler pass:
 * - Collects tagged publishers into a service locator
 * - Returns early when middleware is not defined
 * - Handles tagged service with valid transport
 * - Throws on missing transport attribute
 * - Throws on duplicate transport
 * - Throws when service does not implement OutboxPublisherInterface
 */
#[CoversClass(OutboxPublisherPass::class)]
final class OutboxPublisherPassTest extends TestCase
{
    public function testHandlesNoPublishersGracefully(): void
    {
        $container = $this->createContainerWithMiddleware();

        $pass = new OutboxPublisherPass();
        $pass->process($container);

        $middlewareDef = $container->getDefinition(OutboxPublishingMiddleware::class);
        $locatorArg = $middlewareDef->getArgument('$publisherLocator');
        $this->assertInstanceOf(Reference::class, $locatorArg);
    }

    public function testEarlyReturnWhenMiddlewareNotDefined(): void
    {
        $container = new ContainerBuilder();

        $pass = new OutboxPublisherPass();
        $pass->process($container);

        $this->assertFalse($container->hasDefinition(OutboxPublishingMiddleware::class));
    }

    public function testCollectsTaggedPublishersIntoServiceLocator(): void
    {
        $container = $this->createContainerWithMiddleware();

        $publisherDef = new Definition(TestPublisher::class);
        $publisherDef->addTag('message_broker.outbox_publisher', [
            'transport' => 'outbox',
        ]);
        $container->setDefinition('test.publisher', $publisherDef);

        $pass = new OutboxPublisherPass();
        $pass->process($container);

        $middlewareDef = $container->getDefinition(OutboxPublishingMiddleware::class);
        $locatorArg = $middlewareDef->getArgument('$publisherLocator');
        $this->assertInstanceOf(Reference::class, $locatorArg);
    }

    public function testThrowsOnMissingTransportAttribute(): void
    {
        $container = $this->createContainerWithMiddleware();

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
        $container = $this->createContainerWithMiddleware();

        $publisher1 = new Definition(TestPublisher::class);
        $publisher1->addTag('message_broker.outbox_publisher', [
            'transport' => 'outbox',
        ]);
        $container->setDefinition('test.publisher1', $publisher1);

        $publisher2 = new Definition(TestPublisher::class);
        $publisher2->addTag('message_broker.outbox_publisher', [
            'transport' => 'outbox',
        ]);
        $container->setDefinition('test.publisher2', $publisher2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate outbox publisher for transport "outbox"/');

        $pass = new OutboxPublisherPass();
        $pass->process($container);
    }

    public function testThrowsWhenServiceDoesNotImplementInterface(): void
    {
        $container = $this->createContainerWithMiddleware();

        $publisherDef = new Definition(\stdClass::class);
        $publisherDef->addTag('message_broker.outbox_publisher', [
            'transport' => 'outbox',
        ]);
        $container->setDefinition('test.bad_publisher', $publisherDef);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        $pass = new OutboxPublisherPass();
        $pass->process($container);
    }

    private function createContainerWithMiddleware(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $middlewareDef = new Definition(OutboxPublishingMiddleware::class);
        $middlewareDef->setArgument('$publisherLocator', null);
        $middlewareDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition(OutboxPublishingMiddleware::class, $middlewareDef);

        return $container;
    }
}
