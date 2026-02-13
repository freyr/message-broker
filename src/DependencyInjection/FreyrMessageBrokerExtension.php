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
        /** @var array{inbox: array{message_types: array<string, string>, deduplication_table_name: string}, amqp: array{routing: array<string, array{sender: ?string, routing_key: ?string}>, topology: array{exchanges: array<string, mixed>, queues: array<string, mixed>, bindings: array<int, array{exchange: string, queue: string, binding_key: string, arguments: array<string, mixed>}>}}} $config */
        $this->validateBindingReferences($config['amqp']['topology']);

        $container->setParameter('message_broker.inbox.message_types', $config['inbox']['message_types']);
        $container->setParameter(
            'message_broker.inbox.deduplication_table_name',
            $config['inbox']['deduplication_table_name']
        );
        $container->setParameter('message_broker.amqp.routing_overrides', $config['amqp']['routing']);
        $container->setParameter('message_broker.amqp.topology', $config['amqp']['topology']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));

        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'message_broker';
    }

    /**
     * Validate that bindings reference exchanges and queues defined in the topology.
     *
     * @param array{exchanges: array<string, mixed>, queues: array<string, mixed>, bindings: array<int, array{exchange: string, queue: string, binding_key: string, arguments: array<string, mixed>}>} $topology
     */
    private function validateBindingReferences(array $topology): void
    {
        foreach ($topology['bindings'] as $index => $binding) {
            if (!isset($topology['exchanges'][$binding['exchange']])) {
                throw new \InvalidArgumentException(sprintf(
                    'Binding #%d references undefined exchange "%s".',
                    $index + 1,
                    $binding['exchange'],
                ));
            }

            if (!isset($topology['queues'][$binding['queue']])) {
                throw new \InvalidArgumentException(sprintf(
                    'Binding #%d references undefined queue "%s".',
                    $index + 1,
                    $binding['queue'],
                ));
            }
        }
    }
}
