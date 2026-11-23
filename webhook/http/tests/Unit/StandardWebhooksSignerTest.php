<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook\Http\Tests\Unit;

use Freyr\MessageBroker\Webhook\Http\StandardWebhooksSigner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The known-answer vectors come from the Standard Webhooks repository
 * (standard-webhooks/standard-webhooks, branch main):
 * libraries/go/webhook_test.go (TestWebhookSign) and
 * libraries/javascript/src/webhook.test.ts (the binary payload vector).
 */
final class StandardWebhooksSignerTest extends TestCase
{
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const string MESSAGE_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const int TIMESTAMP = 1614265330;

    public function testSignsTheJsonVectorOfTheSpecification(): void
    {
        $headers = new StandardWebhooksSigner()->headers(
            self::MESSAGE_ID,
            self::TIMESTAMP,
            '{"test": 2432232314}',
            [self::SECRET],
        );

        self::assertSame([
            'webhook-id' => self::MESSAGE_ID,
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
        ], $headers);
    }

    public function testSignsTheBinaryVectorOfTheSpecificationByteForByte(): void
    {
        $body = implode('', array_map(chr(...), range(0, 255)));

        $headers = new StandardWebhooksSigner()->headers(self::MESSAGE_ID, self::TIMESTAMP, $body, [self::SECRET]);

        self::assertSame('v1,sEcZ042G8Crqgxbybb3y0eDxQR3TcfYsk11I1XULhSI=', $headers['webhook-signature']);
    }

    public function testSignsWithEachSecretSeparatedBySpaces(): void
    {
        $second = 'whsec_'.base64_encode(str_repeat("\x2a", 32));
        $body = '{"test": 2432232314}';

        $headers = new StandardWebhooksSigner()->headers(self::MESSAGE_ID, self::TIMESTAMP, $body, [self::SECRET, $second]);

        $expectedSecond = 'v1,'.base64_encode(hash_hmac(
            'sha256',
            self::MESSAGE_ID.'.'.self::TIMESTAMP.'.'.$body,
            str_repeat("\x2a", 32),
            true,
        ));
        self::assertSame(
            'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE= '.$expectedSecond,
            $headers['webhook-signature'],
        );
    }

    public function testRefusesAnEmptyListOfSecrets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StandardWebhooksSigner()->headers(self::MESSAGE_ID, self::TIMESTAMP, '{}', []);
    }

    #[DataProvider('invalidSecrets')]
    public function testRefusesASecretOfAWrongFormWithoutTheSecretInTheMessage(string $secret): void
    {
        try {
            new StandardWebhooksSigner()->headers(self::MESSAGE_ID, self::TIMESTAMP, '{}', [self::SECRET, $secret]);
            self::fail('A secret of a wrong form must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertSame('A webhook secret must have the form whsec_ plus base64', $e->getMessage());
        }
    }

    #[DataProvider('invalidSecrets')]
    public function testIsValidSecretIsFalseForAWrongForm(string $secret): void
    {
        self::assertFalse(StandardWebhooksSigner::isValidSecret($secret));
    }

    public function testIsValidSecretIsTrueForTheFormOfTheSpecification(): void
    {
        self::assertTrue(StandardWebhooksSigner::isValidSecret(self::SECRET));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSecrets(): iterable
    {
        yield 'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'];
        yield 'prefix only' => ['whsec_'];
        yield 'not base64' => ['whsec_not*base64!'];
        yield 'space inside' => ['whsec_MfKQ9r8G KYqrTwjUPD8ILPZIo2LaLaSw'];
        yield 'prefix not at start' => ['xwhsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'];
        yield 'upper case prefix' => ['WHSEC_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'];
    }
}
