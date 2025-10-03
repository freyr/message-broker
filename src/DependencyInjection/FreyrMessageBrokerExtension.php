<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FreyrMessageBrokerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters from configuration
        $container->setParameter('message_broker.inbox.table_name', $config['inbox']['table_name']);
        $container->setParameter('message_broker.inbox.message_types', $config['inbox']['message_types']);
        $container->setParameter('message_broker.inbox.failed_transport', $config['inbox']['failed_transport']);
        $container->setParameter('message_broker.outbox.table_name', $config['outbox']['table_name']);
        $container->setParameter('message_broker.outbox.dlq_transport', $config['outbox']['dlq_transport']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );

        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'message_broker';
    }
}
