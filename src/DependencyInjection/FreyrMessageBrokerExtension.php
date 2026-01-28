<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FreyrMessageBrokerExtension extends Extension
{
    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters from configuration
        /** @var array{inbox: array{message_types: array<string, string>}} $config */
        $container->setParameter('message_broker.inbox.message_types', $config['inbox']['message_types']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));

        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'message_broker';
    }
}
