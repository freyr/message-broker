<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\LuaScript;
use LogicException;
use PHPUnit\Framework\TestCase;

final class LuaScriptTest extends TestCase
{
    public function testTheSha1IsTheSha1OfTheSource(): void
    {
        $script = new LuaScript('return 1');

        self::assertSame('return 1', $script->source);
        self::assertSame('e0e1f9fabfc9d4800c877a703b823ac0578ff8db', $script->sha1);
    }

    public function testTheTwoScriptsOfTheAdapterHaveNoShebangAndUseNoFunction(): void
    {
        foreach ([LuaScript::acquire(), LuaScript::giveBack()] as $script) {
            self::assertStringStartsWith('-- ', $script->source);
            self::assertStringNotContainsString('#!', $script->source);
            self::assertStringNotContainsString('FUNCTION', $script->source);
            self::assertSame(sha1($script->source), $script->sha1);
        }
        self::assertStringContainsString('The acquire of RedisRateLimiter', LuaScript::acquire()->source);
        self::assertStringContainsString('The give-back of RedisRateLimiter', LuaScript::giveBack()->source);
    }

    public function testAFileThatCannotBeReadThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Lua script /nonexistent/acquire.lua cannot be read.');

        LuaScript::fromFile('/nonexistent/acquire.lua');
    }
}
