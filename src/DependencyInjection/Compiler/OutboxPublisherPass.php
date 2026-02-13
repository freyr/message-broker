<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DependencyInjection\Compiler;

use Freyr\MessageBroker\Outbox\OutboxPublisherInterface;
use Freyr\MessageBroker\Outbox\OutboxPublishingMiddleware;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects tagged outbox publishers into the OutboxPublishingMiddleware service locator.
 *
 * Services tagged with 'message_broker.outbox_publisher' must:
 * - Implement OutboxPublisherInterface
 * - Define a 'transport' attribute matching the outbox transport name
 */
final class OutboxPublisherPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OutboxPublishingMiddleware::class)) {
            return;
        }

        $publishers = [];
        foreach ($container->findTaggedServiceIds('message_broker.outbox_publisher') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            if ($class !== null && !is_subclass_of($class, OutboxPublisherInterface::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Service "%s" tagged with "message_broker.outbox_publisher" must implement %s.',
                    $id,
                    OutboxPublisherInterface::class,
                ));
            }

            foreach ($tags as $tag) {
                /** @var array<string, mixed> $tag */
                $transportName = $tag['transport'] ?? null;
                if (!is_string($transportName) || $transportName === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Service "%s" tagged with "message_broker.outbox_publisher" must define "transport" attribute.',
                        $id,
                    ));
                }

                if (isset($publishers[$transportName])) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate outbox publisher for transport "%s": services "%s" and "%s" both claim it.',
                        $transportName,
                        (string) $publishers[$transportName],
                        $id,
                    ));
                }

                $publishers[$transportName] = new Reference($id);
            }
        }

        if ($publishers === []) {
            $container->log($this, 'No outbox publishers registered. OutboxPublishingMiddleware will not publish any messages.');
        }

        $bridge = $container->getDefinition(OutboxPublishingMiddleware::class);
        $bridge->setArgument('$publisherLocator', ServiceLocatorTagPass::register($container, $publishers));
    }
}
