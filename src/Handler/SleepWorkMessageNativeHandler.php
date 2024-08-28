<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Handler;

use Freyr\MessageBroker\Message\MessageHandler;
use Freyr\MessageBroker\Message\SleepMessage;
use JsonSerializable;

class SleepWorkMessageNativeHandler extends MessageHandler
{
    public function handle(SleepMessage $message): bool
    {
        //usleep($message->duration);
        if ($message->shouldAcknowledge) {
            return true;
        } else {
            return false;
        }
    }

    protected static function createMessage(array $body): SleepMessage|JsonSerializable
    {
        return new SleepMessage($body['duration'], $body['shouldAcknowledge']);
    }
}
