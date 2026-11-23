<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\PhpRedisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptError;
use PHPUnit\Framework\TestCase;

final class PhpRedisScriptRunnerTest extends TestCase
{
    private const string SHA1 = 'b9e5a3e3c4d3f0a0b1c2d3e4f5a6b7c8d9e0f1a2';

    private const string SCRIPT = 'return 1';

    public function testACachedScriptRunsWithOneEvalshaWithTheKeysBeforeTheArguments(): void
    {
        $redis = new RecordingPhpRedis([[3, 1]]);

        $result = (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, ['k1', 'k2'], [1, '0.5', 7]);

        self::assertSame([3, 1], $result);
        self::assertSame([['EVALSHA', self::SHA1, ['k1', 'k2', 1, '0.5', 7], 2]], $redis->calls);
    }

    public function testANoscriptReplyGivesOneEvalOfTheScriptWithTheSameKeysAndArguments(): void
    {
        $redis = new RecordingPhpRedis([new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'), [4]]);

        $result = (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, ['k1'], [2]);

        self::assertSame([4], $result);
        self::assertSame([
            ['EVALSHA', self::SHA1, ['k1', 2], 1],
            ['EVAL', self::SCRIPT, ['k1', 2], 1],
        ], $redis->calls);
        self::assertNull($redis->getLastError(), 'the NOSCRIPT error is cleared');
    }

    public function testTheLastErrorIsClearedBeforeTheEvalshaAndBeforeTheEval(): void
    {
        $redis = new RecordingPhpRedis([new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'), [4]], 'ERR an earlier command');

        (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, ['k1'], [2]);

        self::assertSame(['clearLastError', 'EVALSHA', 'clearLastError', 'EVAL'], $redis->log);
    }

    public function testAFalseReplyWithNoErrorIsTheResultOfTheScript(): void
    {
        $redis = new RecordingPhpRedis([false], 'ERR an earlier command');

        self::assertFalse((new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, [], []));
        self::assertCount(1, $redis->calls, 'no EVAL');
    }

    public function testAnErrorOtherThanNoscriptThrowsWithNoEval(): void
    {
        $redis = new RecordingPhpRedis([new ErrorReply('OOM command not allowed when used memory > \'maxmemory\'.')]);

        try {
            (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, ['k1'], [1]);
            self::fail('No exception');
        } catch (RedisScriptError $e) {
            self::assertSame('The Redis script failed: OOM command not allowed when used memory > \'maxmemory\'.', $e->getMessage());
        }
        self::assertCount(1, $redis->calls);
    }

    public function testAnErrorWhoseTextOnlyContainsNoscriptThrows(): void
    {
        $redis = new RecordingPhpRedis([new ErrorReply('ERR Error running script: NOSCRIPT')]);

        $this->expectException(RedisScriptError::class);
        $this->expectExceptionMessage('The Redis script failed: ERR Error running script: NOSCRIPT');
        (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, [], []);
    }

    public function testAnErrorOfTheEvalAfterNoscriptThrows(): void
    {
        $redis = new RecordingPhpRedis([
            new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'),
            new ErrorReply('ERR Error running script (call to f_x): user_script:1: bad'),
        ]);

        try {
            (new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, [], []);
            self::fail('No exception');
        } catch (RedisScriptError $e) {
            self::assertSame('The Redis script failed: ERR Error running script (call to f_x): user_script:1: bad', $e->getMessage());
        }
        self::assertCount(2, $redis->calls);
    }

    public function testAFalseReplyOfTheEvalWithNoErrorIsTheResult(): void
    {
        $redis = new RecordingPhpRedis([new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'), false]);

        self::assertFalse((new PhpRedisScriptRunner($redis))->run(self::SHA1, self::SCRIPT, [], []));
    }
}
