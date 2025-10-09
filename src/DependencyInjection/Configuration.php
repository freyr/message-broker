<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('message_broker');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line
        $rootNode
            ->children()
            ->arrayNode('inbox')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('message_types')
            ->info(
                'Map of message_name => PHP class for MessageNameSerializer (e.g., "order.placed" => "App\Message\OrderPlaced")'
            )
            ->useAttributeAsKey('name')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
