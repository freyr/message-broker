<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\RedisRateLimitConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisRateLimitConfigTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validPrefixes(): iterable
    {
        yield 'one character' => ['a'];
        yield '64 characters' => [str_repeat('x', 64)];
        yield 'each permitted character' => ['AZaz09_.:-'];
    }

    #[DataProvider('validPrefixes')]
    public function testAValidPrefixIsKept(string $prefix): void
    {
        self::assertSame($prefix, (new RedisRateLimitConfig($prefix))->keyPrefix);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPrefixes(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('x', 65)];
        yield 'an opening brace first' => ['{app'];
        yield 'a closing brace last' => ['app}'];
        yield 'a space' => ['my app'];
        yield 'a trailing line feed' => ["app\n"];
        yield 'a slash' => ['app/1'];
        yield 'a non-ASCII letter' => ['zażółć'];
    }

    #[DataProvider('invalidPrefixes')]
    public function testAnInvalidPrefixIsRefused(string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The key prefix must be 1 to 64 characters of A-Z, a-z, 0-9, "_", ".", ":" and "-", '.var_export($prefix, true).' given');

        new RedisRateLimitConfig($prefix);
    }

    public function testTheKeysHaveTheHashTagOfThePrefix(): void
    {
        $config = new RedisRateLimitConfig('app.prod');

        self::assertSame('{mb-rl:app.prod}:g', $config->globalKey());
        self::assertSame('{mb-rl:app.prod}:l:crm', $config->laneKey('crm'));
        self::assertSame('{mb-rl:app.prod}:l:42', $config->laneKey('42'));
    }
}
