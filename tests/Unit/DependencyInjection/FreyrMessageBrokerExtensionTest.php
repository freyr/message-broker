<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DependencyInjection;

use Freyr\MessageBroker\DependencyInjection\FreyrMessageBrokerExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit test for FreyrMessageBrokerExtension.
 *
 * Tests that the extension:
 * - Sets message_broker.inbox.message_types parameter from config
 * - Sets message_broker.inbox.deduplication.table_name parameter from config
 */
#[CoversClass(FreyrMessageBrokerExtension::class)]
final class FreyrMessageBrokerExtensionTest extends TestCase
{
    public function testSetsMessageTypesParameter(): void
    {
        $container = new ContainerBuilder();
        $extension = new FreyrMessageBrokerExtension();

        $extension->load([
            [
                'inbox' => [
                    'message_types' => [
                        'order.placed' => 'App\\Message\\OrderPlaced',
                    ],
                ],
            ],
        ], $container);

        $this->assertSame(
            [
                'order.placed' => 'App\\Message\\OrderPlaced',
            ],
            $container->getParameter('message_broker.inbox.message_types')
        );
    }

    public function testSetsDeduplicationTableNameParameter(): void
    {
        $container = new ContainerBuilder();
        $extension = new FreyrMessageBrokerExtension();

        $extension->load([
            [
                'inbox' => [
                    'deduplication' => [
                        'table_name' => 'custom_dedup',
                    ],
                ],
            ],
        ], $container);

        $this->assertSame(
            'custom_dedup',
            $container->getParameter('message_broker.inbox.deduplication.table_name')
        );
    }
}
