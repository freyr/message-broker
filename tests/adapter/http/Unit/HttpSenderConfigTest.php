<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Unit;

use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpSenderConfigTest extends TestCase
{
    public function testTheDefaultsOfTheSpec(): void
    {
        $config = new HttpSenderConfig();

        self::assertSame(10_000, $config->totalTimeoutMs);
        self::assertSame(3_000, $config->connectTimeoutMs);
        self::assertSame(5_000, $config->dnsAllowanceMs);
        self::assertSame(100, $config->maxHostConnections);
        self::assertSame(300_000, $config->clientResetIntervalMs);
        self::assertNull($config->proxy);
        self::assertTrue($config->blockPrivateNetworks);
        self::assertFalse($config->allowHttp);
        self::assertSame('freyr-message-broker-http', $config->userAgent);
        self::assertTrue($config->checksPrivateNetworks());
    }

    public function testThePrivateNetworkCheckIsOffWhenSwitchedOff(): void
    {
        self::assertFalse(new HttpSenderConfig(blockPrivateNetworks: false)->checksPrivateNetworks());
    }

    public function testAProxySchemeInUpperCaseIsAccepted(): void
    {
        self::assertSame('HTTP://proxy.internal:3128', new HttpSenderConfig(proxy: 'HTTP://proxy.internal:3128')->proxy);
    }

    public function testThePrivateNetworkCheckIsOffWithAProxy(): void
    {
        self::assertFalse(new HttpSenderConfig(proxy: 'http://proxy.internal:3128')->checksPrivateNetworks());
    }

    public function testNoDnsAllowanceIsValidWhenThePrivateNetworkCheckIsOff(): void
    {
        self::assertSame(0, new HttpSenderConfig(dnsAllowanceMs: 0, blockPrivateNetworks: false)->dnsAllowanceMs);
    }

    public function testADnsAllowanceOfOneIsValidWithThePrivateNetworkCheck(): void
    {
        self::assertSame(1, new HttpSenderConfig(dnsAllowanceMs: 1)->dnsAllowanceMs);
    }

    public function testAcceptsTheSmallestValues(): void
    {
        $config = new HttpSenderConfig(
            totalTimeoutMs: 1,
            connectTimeoutMs: 1,
            dnsAllowanceMs: 0,
            maxHostConnections: 1,
            clientResetIntervalMs: 1,
            proxy: 'https://user:pass@proxy.internal',
        );

        self::assertSame(1, $config->totalTimeoutMs);
        self::assertSame(0, $config->dnsAllowanceMs);
        self::assertSame('https://user:pass@proxy.internal', $config->proxy);
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidConfigs')]
    public function testRefusesAnInvalidValue(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new HttpSenderConfig(...$arguments);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'total 0' => [[
            'totalTimeoutMs' => 0,
        ], '$totalTimeoutMs must be 1 or more, 0 given'];
        yield 'connect 0' => [[
            'connectTimeoutMs' => 0,
        ], '$connectTimeoutMs must be 1 or more, 0 given'];
        yield 'host connections 0' => [[
            'maxHostConnections' => 0,
        ], '$maxHostConnections must be 1 or more, 0 given'];
        yield 'reset 0' => [[
            'clientResetIntervalMs' => 0,
        ], '$clientResetIntervalMs must be 1 or more, 0 given'];
        yield 'no dns allowance with the private network check' => [[
            'dnsAllowanceMs' => 0,
        ], '$dnsAllowanceMs must be 1 or more when the private network check is on, 0 given'];
        yield 'dns negative' => [[
            'dnsAllowanceMs' => -1,
        ], '$dnsAllowanceMs must be 0 or more, -1 given'];
        yield 'connect above total' => [[
            'totalTimeoutMs' => 2_000,
            'connectTimeoutMs' => 2_001,
        ], '$connectTimeoutMs must not be longer than $totalTimeoutMs'];
        yield 'empty user agent' => [[
            'userAgent' => '',
        ], '$userAgent must be a non-empty header value'];
        yield 'user agent with a line break' => [[
            'userAgent' => "a\r\nx-other: b",
        ], '$userAgent must be a non-empty header value'];
        yield 'proxy not a url' => [[
            'proxy' => 'proxy.internal',
        ], '$proxy must be an http or https URL with a host'];
        yield 'proxy other scheme' => [[
            'proxy' => 'socks5://proxy.internal',
        ], '$proxy must be an http or https URL with a host'];
        yield 'proxy without host' => [[
            'proxy' => 'http:/proxy',
        ], '$proxy must be an http or https URL with a host'];
        yield 'proxy empty' => [[
            'proxy' => '',
        ], '$proxy must be an http or https URL with a host'];
    }

    public function testTheProxyIsNotInTheMessageOfARefusal(): void
    {
        try {
            new HttpSenderConfig(proxy: 'ftp://user:topsecret@proxy.internal');
            self::fail('An invalid proxy must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('topsecret', $e->getMessage());
            self::assertStringNotContainsString('proxy.internal', $e->getMessage());
        }
    }
}
