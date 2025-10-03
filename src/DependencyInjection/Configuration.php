<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('message_broker');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('inbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_name')
                            ->info('Database table name for inbox messages')
                            ->defaultValue('messenger_inbox')
                        ->end()
                        ->arrayNode('message_types')
                            ->info('Map of message_name => PHP class for typed inbox deserialization')
                            ->useAttributeAsKey('name')
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                        ->end()
                        ->scalarNode('failed_transport')
                            ->info('Transport name for failed inbox messages')
                            ->defaultValue('failed')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('outbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_name')
                            ->info('Database table name for outbox messages')
                            ->defaultValue('messenger_outbox')
                        ->end()
                        ->scalarNode('dlq_transport')
                            ->info('Transport name for dead-letter queue (unmatched events)')
                            ->defaultValue('dlq')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
