<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Closure;

/**
 * Adapts a plain callable into a MessageDispatcher. Lets an application wire a
 * closure without the separate routing component, and gives tests a trivial
 * dispatch target. The real command-bus adapter implements MessageDispatcher
 * directly.
 */
final readonly class CallableDispatcher implements MessageDispatcher
{
    private Closure $dispatch;

    /** @param callable(IncomingMessage): void $dispatch */
    public function __construct(callable $dispatch)
    {
        $this->dispatch = $dispatch(...);
    }

    public function dispatch(IncomingMessage $message): void
    {
        ($this->dispatch)($message);
    }
}
