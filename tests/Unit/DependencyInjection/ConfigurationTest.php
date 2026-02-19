<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DependencyInjection;

use Freyr\MessageBroker\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Unit tests for Configuration tree builder.
 */
final class ConfigurationTest extends TestCase
{
    public function testEmptyConfigIsValid(): void
    {
        $config = $this->processConfig([]);

        $this->assertSame([], $config['inbox']['message_types']);
        $this->assertSame('message_broker_deduplication', $config['inbox']['deduplication']['table_name']);
    }

    public function testInboxMessageTypes(): void
    {
        $config = $this->processConfig([
            'inbox' => [
                'message_types' => [
                    'order.placed' => 'App\\Message\\OrderPlaced',
                    'user.registered' => 'App\\Message\\UserRegistered',
                ],
            ],
        ]);

        $this->assertSame([
            'order.placed' => 'App\\Message\\OrderPlaced',
            'user.registered' => 'App\\Message\\UserRegistered',
        ], $config['inbox']['message_types']);
    }

    public function testCustomDeduplicationTableName(): void
    {
        $config = $this->processConfig([
            'inbox' => [
                'deduplication' => [
                    'table_name' => 'custom_dedup_table',
                ],
            ],
        ]);

        $this->assertSame('custom_dedup_table', $config['inbox']['deduplication']['table_name']);
    }

    public function testDeduplicationTableNameCannotBeEmpty(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'inbox' => [
                'deduplication' => [
                    'table_name' => '',
                ],
            ],
        ]);
    }

    public function testDeduplicationDefaultsAreApplied(): void
    {
        $config = $this->processConfig([
            'inbox' => [
                'deduplication' => [],
            ],
        ]);

        $this->assertSame('message_broker_deduplication', $config['inbox']['deduplication']['table_name']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{inbox: array{message_types: array<string, string>, deduplication: array{table_name: string}}}
     */
    private function processConfig(array $config): array
    {
        $processor = new Processor();

        /** @var array{inbox: array{message_types: array<string, string>, deduplication: array{table_name: string}}} */
        return $processor->processConfiguration(new Configuration(), [$config]);
    }
}
