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

        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('inbox')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('message_types')
            ->info(
                'Map of message_name => PHP class for InboxSerializer (e.g., "order.placed" => "App\\Message\\OrderPlaced")',
            )
            ->useAttributeAsKey('name')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->end()
            ->arrayNode('deduplication')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('table_name')
            ->info('Database table name for deduplication tracking')
            ->defaultValue('message_broker_deduplication')
            ->cannotBeEmpty()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
