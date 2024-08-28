<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Handler;

use Freyr\MessageBroker\Message\SleepMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SleepWorkMessageHandler
{
    public function __invoke(SleepMessage $message): bool
    {
        //usleep($message->duration);
        if ($message->shouldAcknowledge) {
            return true;
        } else {
            return false;
        }
    }
}
