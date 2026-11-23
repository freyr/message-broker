<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\PredisScriptRunner;
use Freyr\MessageBroker\RateLimit\Redis\RedisScriptError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PredisScriptRunnerTest extends TestCase
{
    private const string SHA1 = 'b9e5a3e3c4d3f0a0b1c2d3e4f5a6b7c8d9e0f1a2';

    private const string SCRIPT = 'return 1';

    public function testACachedScriptRunsWithOneEvalshaWithTheKeysBeforeTheArgumentsAsStrings(): void
    {
        $client = new RecordingPredis([[3, 1]]);

        $result = (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, ['k1', 'k2'], [1, '0.5', 7]);

        self::assertSame([3, 1], $result);
        self::assertSame([['EVALSHA', [self::SHA1, 2, 'k1', 'k2', '1', '0.5', '7']]], $client->calls);
    }

    /** @return iterable<string, array{bool}> */
    public static function errorModes(): iterable
    {
        yield 'a thrown ServerException' => [true];
        yield 'a returned Predis\Response\Error' => [false];
    }

    #[DataProvider('errorModes')]
    public function testANoscriptReplyGivesOneEvalOfTheScriptWithTheSameKeysAndArguments(bool $exceptions): void
    {
        $client = new RecordingPredis([new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'), [4]], $exceptions);

        $result = (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, ['k1'], [2]);

        self::assertSame([4], $result);
        self::assertSame([
            ['EVALSHA', [self::SHA1, 1, 'k1', '2']],
            ['EVAL', [self::SCRIPT, 1, 'k1', '2']],
        ], $client->calls);
    }

    #[DataProvider('errorModes')]
    public function testAnErrorOtherThanNoscriptThrowsWithNoEval(bool $exceptions): void
    {
        $client = new RecordingPredis([new ErrorReply('OOM command not allowed when used memory > \'maxmemory\'.')], $exceptions);

        try {
            (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, ['k1'], [1]);
            self::fail('No exception');
        } catch (RedisScriptError $e) {
            self::assertSame('The Redis script failed: OOM command not allowed when used memory > \'maxmemory\'.', $e->getMessage());
        }
        self::assertCount(1, $client->calls);
    }

    #[DataProvider('errorModes')]
    public function testAnErrorOfTheEvalAfterNoscriptThrows(bool $exceptions): void
    {
        $client = new RecordingPredis([
            new ErrorReply('NOSCRIPT No matching script. Please use EVAL.'),
            new ErrorReply('ERR Error running script (call to f_x): user_script:1: bad'),
        ], $exceptions);

        try {
            (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, [], []);
            self::fail('No exception');
        } catch (RedisScriptError $e) {
            self::assertSame('The Redis script failed: ERR Error running script (call to f_x): user_script:1: bad', $e->getMessage());
        }
        self::assertCount(2, $client->calls);
    }

    public function testAnErrorWhoseTextOnlyContainsNoscriptThrows(): void
    {
        $client = new RecordingPredis([new ErrorReply('ERR Error running script: NOSCRIPT')]);

        $this->expectException(RedisScriptError::class);
        $this->expectExceptionMessage('The Redis script failed: ERR Error running script: NOSCRIPT');
        (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, [], []);
    }

    public function testAThrownServerExceptionIsThePreviousOfTheError(): void
    {
        $client = new RecordingPredis([new ErrorReply('ERR wrong')]);

        try {
            (new PredisScriptRunner($client))->run(self::SHA1, self::SCRIPT, [], []);
            self::fail('No exception');
        } catch (RedisScriptError $e) {
            self::assertInstanceOf(\Predis\Response\ServerException::class, $e->getPrevious());
        }
    }
}
