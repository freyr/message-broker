<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Amqp;

use Freyr\MessageBroker\Amqp\AmqpConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AmqpConnectionFactory.
 *
 * Tests DSN parsing, vhost extraction, and credential sanitisation.
 */
final class AmqpConnectionFactoryTest extends TestCase
{
    private AmqpConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AmqpConnectionFactory();
    }

    public function testExtractVhostFromEncodedSlash(): void
    {
        $this->assertSame('/', $this->factory->extractVhost('amqp://guest:guest@localhost:5672/%2f'));
    }

    public function testExtractVhostFromNamedVhost(): void
    {
        $this->assertSame('my-app', $this->factory->extractVhost('amqp://guest:guest@localhost:5672/my-app'));
    }

    public function testExtractVhostDefaultsToSlash(): void
    {
        $this->assertSame('/', $this->factory->extractVhost('amqp://guest:guest@localhost:5672'));
    }

    public function testExtractVhostFromMalformedDsn(): void
    {
        $this->assertSame('/', $this->factory->extractVhost('http:///'));
    }

    public function testSanitiseDsnRedactsCredentials(): void
    {
        $sanitised = $this->factory->sanitiseDsn('amqp://admin:s3cret@rabbit.example.com:5672/%2f');

        $this->assertSame('amqp://***:***@rabbit.example.com:5672/%2f', $sanitised);
    }

    public function testSanitiseDsnWithoutCredentials(): void
    {
        $dsn = 'amqp://localhost:5672/%2f';

        $this->assertSame($dsn, $this->factory->sanitiseDsn($dsn));
    }

    public function testCreateConnectionThrowsOnMalformedDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AMQP DSN');

        $this->factory->createConnection('http:///');
    }

    public function testMalformedDsnErrorMessageIsSanitised(): void
    {
        $sanitised = $this->factory->sanitiseDsn('amqp://admin:s3cret@host:5672/%2f');

        $this->assertStringNotContainsString('s3cret', $sanitised);
        $this->assertStringNotContainsString('admin', $sanitised);
    }
}
