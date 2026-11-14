<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\ErrorHandler;
use Throwable;

final class RecordingErrorHandler implements ErrorHandler
{
    /** @var list<array{error: Throwable, context: array<string, mixed>}> */
    public array $calls = [];

    public function handle(Throwable $error, array $context = []): void
    {
        $this->calls[] = [
            'error' => $error,
            'context' => $context,
        ];
    }
}
