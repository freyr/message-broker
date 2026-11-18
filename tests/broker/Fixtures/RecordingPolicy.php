<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Consumer\IncomingMessage;
use Freyr\MessageBroker\Retry\RetryDecision;
use Freyr\MessageBroker\Retry\RetryPolicy;
use Throwable;

final class RecordingPolicy implements RetryPolicy
{
    public ?int $attempt = null;

    public ?Throwable $error = null;

    public ?IncomingMessage $message = null;

    public function decide(int $attempt, Throwable $error, IncomingMessage $message): RetryDecision
    {
        $this->attempt = $attempt;
        $this->error = $error;
        $this->message = $message;

        return RetryDecision::deadLetter();
    }
}
