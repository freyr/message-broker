<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\DeadLetter\DeadLetterHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The value of the header x-dead-letter: encode, the cuts, and a decode that never throws. */
final class DeadLetterHeaderTest extends TestCase
{
    private const string ID = '0190f7e2-1234-7000-8000-abc123456789';

    private static function deadLetter(
        string $errorMessage = 'card expired',
        string $errorTrace = '#0 a/b.php(1): fail()',
        bool $replayable = true,
    ): DeadLetter {
        return new DeadLetter(
            id: self::ID,
            source: 'orders_q',
            messageId: 'm-1',
            messageName: 'order.placed',
            body: '{"x":1}',
            headers: [
                'x-custom' => 'h',
            ],
            errorClass: 'App\PaymentDeclined',
            errorMessage: $errorMessage,
            errorTrace: $errorTrace,
            attempts: 5,
            failedAt: 1_795_219_200_123,
            replayable: $replayable,
        );
    }

    /** @return array<string, mixed> the decoded value of a valid header */
    private static function valid(): array
    {
        return [
            'id' => self::ID,
            'source' => 'orders_q',
            'message_id' => 'm-1',
            'message_name' => 'order.placed',
            'error_class' => 'App\PaymentDeclined',
            'error_message' => 'card expired',
            'error_trace' => '#0 trace',
            'attempts' => 5,
            'failed_at' => 1_795_219_200_123,
            'replayable' => true,
        ];
    }

    public function testEncodeGivesOneJsonObjectWithTheTenKeysAndTheMinimumOfEscapes(): void
    {
        $encoded = DeadLetterHeader::encode(self::deadLetter(errorMessage: 'zażółć a/b'));

        self::assertSame(
            '{"id":"0190f7e2-1234-7000-8000-abc123456789","source":"orders_q","message_id":"m-1",'
            .'"message_name":"order.placed","error_class":"App\\\\PaymentDeclined","error_message":"zażółć a/b",'
            .'"error_trace":"#0 a/b.php(1): fail()","attempts":5,"failed_at":1795219200123,"replayable":true}',
            $encoded,
        );
    }

    public function testTheRoundTripKeepsEachKey(): void
    {
        $deadLetter = self::deadLetter(errorMessage: "line 1\nzażółć \"q\"", replayable: false);

        self::assertSame([
            'id' => self::ID,
            'source' => 'orders_q',
            'message_id' => 'm-1',
            'message_name' => 'order.placed',
            'error_class' => 'App\PaymentDeclined',
            'error_message' => "line 1\nzażółć \"q\"",
            'error_trace' => '#0 a/b.php(1): fail()',
            'attempts' => 5,
            'failed_at' => 1_795_219_200_123,
            'replayable' => false,
        ], DeadLetterHeader::decode(DeadLetterHeader::encode($deadLetter)));
    }

    public function testTheErrorMessageIsCutTo1024Bytes(): void
    {
        $decoded = DeadLetterHeader::decode(DeadLetterHeader::encode(self::deadLetter(errorMessage: str_repeat('a', 1025))));

        self::assertNotNull($decoded);
        self::assertSame(str_repeat('a', 1024), $decoded['error_message']);
    }

    public function testAnErrorMessageOf1024BytesIsNotCut(): void
    {
        $decoded = DeadLetterHeader::decode(DeadLetterHeader::encode(self::deadLetter(errorMessage: str_repeat('a', 1024))));

        self::assertNotNull($decoded);
        self::assertSame(str_repeat('a', 1024), $decoded['error_message']);
    }

    public function testTheErrorTraceIsCutTo16384Bytes(): void
    {
        $decoded = DeadLetterHeader::decode(DeadLetterHeader::encode(self::deadLetter(errorTrace: str_repeat('t', 16385))));

        self::assertNotNull($decoded);
        self::assertSame(str_repeat('t', 16384), $decoded['error_trace']);
    }

    public function testACutInsideAMultiByteCharacterGivesValidUtf8(): void
    {
        // 'a' and then 2-byte characters: byte 1024 is the second byte of a character.
        $message = 'a'.str_repeat('ż', 600);
        $trace = 'a'.str_repeat('ż', 9000);

        $decoded = DeadLetterHeader::decode(DeadLetterHeader::encode(self::deadLetter(errorMessage: $message, errorTrace: $trace)));

        self::assertNotNull($decoded);
        self::assertSame('a'.str_repeat('ż', 511), $decoded['error_message']);
        self::assertSame('a'.str_repeat('ż', 8191), $decoded['error_trace']);
        self::assertTrue(mb_check_encoding($decoded['error_message'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($decoded['error_trace'], 'UTF-8'));
    }

    public function testAValidValueDecodes(): void
    {
        self::assertSame(self::valid(), DeadLetterHeader::decode((string) json_encode(self::valid())));
    }

    public function testAnUnknownKeyIsIgnored(): void
    {
        $value = self::valid() + [
            'added_later' => [1, 2],
        ];

        self::assertSame(self::valid(), DeadLetterHeader::decode((string) json_encode($value)));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notAString(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'array' => [self::valid()];
        yield 'boolean' => [true];
    }

    #[DataProvider('notAString')]
    public function testAValueThatIsNotAStringDecodesToNull(mixed $value): void
    {
        self::assertNull(DeadLetterHeader::decode($value));
    }

    /** @return iterable<string, array{string}> */
    public static function notAJsonObject(): iterable
    {
        yield 'invalid JSON' => ['{"id":'];
        yield 'empty' => [''];
        yield 'a JSON list' => [(string) json_encode(array_values(self::valid()))];
        yield 'an empty JSON list' => ['[]'];
        yield 'an empty JSON object' => ['{}'];
        yield 'a JSON string' => ['"text"'];
        yield 'a JSON number' => ['5'];
    }

    #[DataProvider('notAJsonObject')]
    public function testAValueThatIsNotAJsonObjectDecodesToNull(string $value): void
    {
        self::assertNull(DeadLetterHeader::decode($value));
    }

    /** @return iterable<string, array{string}> */
    public static function keys(): iterable
    {
        foreach (array_keys(self::valid()) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('keys')]
    public function testAnAbsentKeyDecodesToNull(string $key): void
    {
        $value = self::valid();
        unset($value[$key]);

        self::assertNull(DeadLetterHeader::decode((string) json_encode($value)));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function wrongTypes(): iterable
    {
        foreach (['id', 'source', 'message_id', 'message_name', 'error_class', 'error_message', 'error_trace'] as $key) {
            yield "{$key} integer" => [$key, 1];
            yield "{$key} null" => [$key, null];
            yield "{$key} object" => [$key, [
                'a' => 'b',
            ]];
        }
        foreach (['attempts', 'failed_at'] as $key) {
            yield "{$key} string" => [$key, '5'];
            yield "{$key} float" => [$key, 5.5];
            yield "{$key} null" => [$key, null];
            yield "{$key} boolean" => [$key, true];
        }
        yield 'replayable integer' => ['replayable', 1];
        yield 'replayable string' => ['replayable', 'true'];
        yield 'replayable null' => ['replayable', null];
    }

    #[DataProvider('wrongTypes')]
    public function testAKeyOfAWrongTypeDecodesToNull(string $key, mixed $wrong): void
    {
        $value = self::valid();
        $value[$key] = $wrong;

        self::assertNull(DeadLetterHeader::decode((string) json_encode($value)));
    }

    /** @return iterable<string, array{string}> */
    public static function notAUuid(): iterable
    {
        yield 'text' => ['not-a-uuid'];
        yield 'empty' => [''];
        yield 'a trailing newline' => [self::ID."\n"];
        yield 'one character short' => [substr(self::ID, 0, -1)];
    }

    #[DataProvider('notAUuid')]
    public function testAnIdThatIsNotAUuidDecodesToNull(string $id): void
    {
        $value = self::valid();
        $value['id'] = $id;

        self::assertNull(DeadLetterHeader::decode((string) json_encode($value)));
    }
}
