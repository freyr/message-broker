<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Adapter\Amqp\AmqpLaneLimits;
use PHPUnit\Framework\TestCase;

final class AmqpLaneLimitsTest extends TestCase
{
    public function testTheRabbitMqDefaultsAre15MibForTheBodyAnd48KibForTheHeaders(): void
    {
        $limits = AmqpLaneLimits::rabbitMqDefaults();

        self::assertSame(15 * 1024 * 1024, $limits->maxBodyBytes);
        self::assertSame(48 * 1024, $limits->maxHeaderBytes);
    }

    public function testTheBodyLimitKeepsAMarginBelowTheBrokerLimit(): void
    {
        self::assertSame(16 * 1024 * 1024, AmqpLaneLimits::BROKER_MAX_MESSAGE_SIZE);
        self::assertSame(131_064, AmqpLaneLimits::BROKER_MAX_FRAME_PAYLOAD);
        self::assertLessThan(AmqpLaneLimits::BROKER_MAX_MESSAGE_SIZE, AmqpLaneLimits::MAX_BODY_BYTES);
        self::assertLessThan(AmqpLaneLimits::BROKER_MAX_FRAME_PAYLOAD, AmqpLaneLimits::MAX_HEADER_BYTES * 5 / 2);
    }
}
