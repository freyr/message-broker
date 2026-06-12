<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Retry;

enum RetryAction
{
    case Retry;
    case DeadLetter;
    case Discard;
}
