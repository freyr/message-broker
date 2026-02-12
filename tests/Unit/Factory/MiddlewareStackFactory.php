<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

/**
 * Factory for creating middleware stacks used in unit tests.
 */
final class MiddlewareStackFactory
{
    /**
     * Create a stack that tracks whether the next middleware was called.
     *
     * Usage:
     *   $nextCalled = false;
     *   $stack = MiddlewareStackFactory::createTracking($nextCalled);
     *   $middleware->handle($envelope, $stack);
     *   $this->assertTrue($nextCalled);
     */
    public static function createTracking(bool &$nextCalled): StackInterface
    {
        $tracking = new class($nextCalled) implements MiddlewareInterface {
            public function __construct(
                private bool &$called, // @phpstan-ignore property.onlyWritten (read via reference in outer scope)
            ) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->called = true;

                return $envelope;
            }
        };

        return new StackMiddleware($tracking);
    }

    /**
     * Create a no-op pass-through stack for tests that don't need to track calls.
     */
    public static function createPassThrough(): StackInterface
    {
        $noOp = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };

        return new StackMiddleware($noOp);
    }
}
