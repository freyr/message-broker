<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Webhook;

use Freyr\MessageBroker\Webhook\Rate;
use Freyr\MessageBroker\Webhook\Subscription;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testKeepsTheIdTheUrlAndTheSecrets(): void
    {
        $subscription = new Subscription('crm', 'https://crm.example/hook', ['whsec_a', 'whsec_b']);

        self::assertSame('crm', $subscription->id);
        self::assertSame('https://crm.example/hook', $subscription->url);
        self::assertSame(['whsec_a', 'whsec_b'], $subscription->secrets);
        self::assertNull($subscription->rateLimit, 'no lane limit by default');
    }

    public function testKeepsTheRateLimitOfTheLane(): void
    {
        $rate = new Rate(40.0, 4);

        $subscription = new Subscription('crm', 'https://crm.example/hook', ['whsec_a'], $rate);

        self::assertSame($rate, $subscription->rateLimit);
    }

    public function testAcceptsAnIdOf64Characters(): void
    {
        $subscription = new Subscription(str_repeat('ł', 64), 'https://x.example', ['whsec_a']);

        self::assertSame(64, mb_strlen($subscription->id), 'the limit counts characters, not bytes');
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty id' => ['', ['whsec_a'], 'Subscription id must be 1 to 64 characters, got 0'];
        yield 'over-long id' => [str_repeat('l', 65), ['whsec_a'], 'Subscription id must be 1 to 64 characters, got 65'];
        yield 'no secret' => ['crm', [], 'Subscription "crm" needs one or more secrets'];
    }

    /** @param list<string> $secrets */
    #[DataProvider('invalidValues')]
    public function testRejectsAnInvalidValue(string $id, array $secrets, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Subscription($id, 'https://x.example', $secrets);
    }

    public function testTheErrorOfAnInvalidIdHoldsNoUrlAndNoSecret(): void
    {
        try {
            new Subscription('', 'https://secret-host.example/token', ['whsec_secret']);
            self::fail('no exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
