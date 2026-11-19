<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\DeadLetter;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The text invariant of a dead letter: each text that a sink stores is valid
 * UTF-8 with no NUL character, and each name fits 255 characters. The body
 * stays the exact bytes.
 */
final class DeadLetterTest extends TestCase
{
    private const string FFFD = "\u{FFFD}";

    /** @return iterable<string, array{string, string}> */
    public static function invalidTexts(): iterable
    {
        yield 'invalid byte' => ["a\xffb", 'a'.self::FFFD.'b'];
        yield 'truncated multibyte sequence' => ["a\xc3", 'a'.self::FFFD];
        yield 'NUL character' => ["a\x00b", 'a'.self::FFFD.'b'];
        yield 'invalid byte and NUL character' => ["\x00\xfe", self::FFFD.self::FFFD];
        yield 'valid multibyte text' => ['zażółć', 'zażółć'];
    }

    #[DataProvider('invalidTexts')]
    public function testEachTextFieldBecomesValidUtf8WithNoNulCharacter(string $input, string $expected): void
    {
        $deadLetter = self::deadLetter(
            source: $input,
            messageId: $input,
            messageName: $input,
            errorClass: $input,
            errorMessage: $input,
            errorTrace: $input,
        );

        self::assertSame($expected, $deadLetter->source);
        self::assertSame($expected, $deadLetter->messageId);
        self::assertSame($expected, $deadLetter->messageName);
        self::assertSame($expected, $deadLetter->errorClass);
        self::assertSame($expected, $deadLetter->errorMessage);
        self::assertSame($expected, $deadLetter->errorTrace);
    }

    #[DataProvider('invalidTexts')]
    public function testEachHeaderKeyAndStringValueBecomesValidUtf8AlsoNested(string $input, string $expected): void
    {
        $deadLetter = self::deadLetter(headers: [
            'x-'.$input => $input,
            'x-nested' => [
                'k-'.$input => $input,
                'list' => [$input, 7],
            ],
        ]);

        self::assertSame([
            'x-'.$expected => $expected,
            'x-nested' => [
                'k-'.$expected => $expected,
                'list' => [$expected, 7],
            ],
        ], $deadLetter->headers);
    }

    public function testHeaderValuesThatAreNotStringsStayUnchanged(): void
    {
        $headers = [
            'x-int' => 3,
            'x-float' => 1.5,
            'x-bool' => false,
            'x-null' => null,
            'x-text' => 'plain',
            'x-list' => [1, true, 'a'],
            7 => 'integer key',
        ];

        self::assertSame($headers, self::deadLetter(headers: $headers)->headers);
    }

    /** @return iterable<string, array{string}> */
    public static function bodies(): iterable
    {
        yield 'invalid UTF-8' => ["a\xff\xfeb"];
        yield 'NUL byte' => ["a\x00b"];
        yield 'long binary' => [str_repeat("\x00\xff", 300)];
    }

    #[DataProvider('bodies')]
    public function testTheBodyStaysByteEqual(string $body): void
    {
        self::assertSame($body, self::deadLetter(body: $body)->body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function longNames(): iterable
    {
        yield 'single-byte characters' => [str_repeat('a', 300), str_repeat('a', 255)];
        yield 'multibyte characters' => [str_repeat('ż', 300), str_repeat('ż', 255)];
        yield 'invalid bytes count as one character each after the substitution' => [
            str_repeat("\xff", 300),
            str_repeat(self::FFFD, 255),
        ];
    }

    #[DataProvider('longNames')]
    public function testEachNameIsCutTo255Characters(string $input, string $expected): void
    {
        $deadLetter = self::deadLetter(source: $input, messageId: $input, messageName: $input, errorClass: $input);

        self::assertSame($expected, $deadLetter->source);
        self::assertSame($expected, $deadLetter->messageId);
        self::assertSame($expected, $deadLetter->messageName);
        self::assertSame($expected, $deadLetter->errorClass);
    }

    public function testANameOfExactly255CharactersStaysUnchanged(): void
    {
        $name = str_repeat('ż', 254).'a';
        $deadLetter = self::deadLetter(source: $name, messageId: $name, messageName: $name, errorClass: $name);

        self::assertSame($name, $deadLetter->source);
        self::assertSame($name, $deadLetter->messageId);
        self::assertSame($name, $deadLetter->messageName);
        self::assertSame($name, $deadLetter->errorClass);
    }

    public function testTheErrorTextsAreNotCut(): void
    {
        $long = str_repeat('e', 1_000);
        $deadLetter = self::deadLetter(errorMessage: $long, errorTrace: $long);

        self::assertSame($long, $deadLetter->errorMessage);
        self::assertSame($long, $deadLetter->errorTrace);
    }

    public function testTheOtherFieldsStayUnchanged(): void
    {
        $deadLetter = new DeadLetter(
            id: 'dl-1',
            source: 'orders_q',
            messageId: 'm-1',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            errorClass: RuntimeException::class,
            errorMessage: 'boom',
            errorTrace: '#0 trace',
            attempts: 4,
            failedAt: 1_765_476_000_123,
            replayedAt: 1_765_476_000_456,
        );

        self::assertSame('dl-1', $deadLetter->id);
        self::assertSame(4, $deadLetter->attempts);
        self::assertSame(1_765_476_000_123, $deadLetter->failedAt);
        self::assertSame(1_765_476_000_456, $deadLetter->replayedAt);
        self::assertNull(self::deadLetter()->replayedAt);
    }

    public function testFromFailureAppliesTheSameRules(): void
    {
        $previous = new RuntimeException("root\x00cause");
        $error = new RuntimeException("handler \xff failed", 0, $previous);
        $name = str_repeat('ż', 300);

        $deadLetter = DeadLetter::fromFailure(
            source: "q\x00".$name,
            messageId: "m\xff".$name,
            messageName: $name,
            body: "raw\x00\xff",
            headers: [
                "x-\xff" => [
                    'deep' => "v\x00",
                ],
            ],
            error: $error,
            attempts: 2,
        );

        self::assertSame('q'.self::FFFD.str_repeat('ż', 253), $deadLetter->source);
        self::assertSame('m'.self::FFFD.str_repeat('ż', 253), $deadLetter->messageId);
        self::assertSame(str_repeat('ż', 255), $deadLetter->messageName);
        self::assertSame("raw\x00\xff", $deadLetter->body);
        self::assertSame([
            'x-'.self::FFFD => [
                'deep' => 'v'.self::FFFD,
            ],
        ], $deadLetter->headers);
        self::assertSame(RuntimeException::class, $deadLetter->errorClass);
        self::assertSame('handler '.self::FFFD.' failed <- root'.self::FFFD.'cause', $deadLetter->errorMessage);
        self::assertTrue(mb_check_encoding($deadLetter->errorTrace, 'UTF-8'));
        self::assertSame(2, $deadLetter->attempts);
    }

    /** @param array<string, mixed> $headers */
    private static function deadLetter(
        string $source = 'orders_q',
        string $messageId = 'm-1',
        string $messageName = 'order.placed',
        string $body = '{}',
        array $headers = [],
        string $errorClass = RuntimeException::class,
        string $errorMessage = 'boom',
        string $errorTrace = '#0 trace',
    ): DeadLetter {
        return new DeadLetter(
            id: 'dl-1',
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: $body,
            headers: $headers,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            errorTrace: $errorTrace,
            attempts: 1,
            failedAt: 1_765_476_000_123,
        );
    }
}
