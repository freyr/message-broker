<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Consumer;

use Freyr\MessageBroker\Consumer\CallableDispatcher;
use Freyr\MessageBroker\Consumer\IncomingMessage;
use PHPUnit\Framework\TestCase;

final class CallableDispatcherTest extends TestCase
{
    public function testForwardsTheIncomingMessageToTheCallable(): void
    {
        $received = null;
        $dispatcher = new CallableDispatcher(function (IncomingMessage $message) use (&$received): void {
            $received = $message;
        });

        $incoming = new IncomingMessage(
            messageId: 'm-1',
            messageName: 'order.placed',
            createdAt: 1_700_000_000_000,
            payload: [
                'order_id' => 'o-1',
            ],
            headers: [
                'x-message-id' => 'm-1',
            ],
        );

        $dispatcher->dispatch($incoming);

        self::assertSame($incoming, $received);
    }

    public function testPropagatesExceptionsFromTheCallable(): void
    {
        $dispatcher = new CallableDispatcher(static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $dispatcher->dispatch(new IncomingMessage('m-1', 'order.placed', 1, []));
    }
}
