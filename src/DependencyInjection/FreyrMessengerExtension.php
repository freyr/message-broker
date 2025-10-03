<?php

declare(strict_types=1);

namespace Freyr\Messenger\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FreyrMessengerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters from configuration
        $container->setParameter('freyr_messenger.inbox.table_name', $config['inbox']['table_name']);
        $container->setParameter('freyr_messenger.inbox.message_types', $config['inbox']['message_types']);
        $container->setParameter('freyr_messenger.inbox.failed_transport', $config['inbox']['failed_transport']);
        $container->setParameter('freyr_messenger.outbox.table_name', $config['outbox']['table_name']);
        $container->setParameter('freyr_messenger.outbox.dlq_transport', $config['outbox']['dlq_transport']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );

        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'freyr_messenger';
    }
}
