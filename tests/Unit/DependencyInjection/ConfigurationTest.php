<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DependencyInjection;

use Freyr\MessageBroker\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Unit test for Configuration tree builder.
 *
 * Tests that the configuration:
 * - Accepts empty config with defaults
 * - Accepts custom message_types mapping
 * - Accepts custom table name
 * - Rejects empty table name
 * - Rejects SQL injection characters in table name
 * - Rejects table name starting with number
 * - Accepts valid table names (alphanumeric + underscore)
 */
#[CoversClass(Configuration::class)]
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
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'inbox' => [
                'deduplication' => [
                    'table_name' => '',
                ],
            ],
        ]);
    }

    public function testDeduplicationTableNameRejectsSpecialCharacters(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Table name must contain only alphanumeric characters and underscores');

        $this->processConfig([
            'inbox' => [
                'deduplication' => [
                    'table_name' => "'; DROP TABLE --",
                ],
            ],
        ]);
    }

    public function testDeduplicationTableNameRejectsStartingWithNumber(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'inbox' => [
                'deduplication' => [
                    'table_name' => '1invalid_table',
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
