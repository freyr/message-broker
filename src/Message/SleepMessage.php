<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Message;

use JsonSerializable;

readonly class SleepMessage implements JsonSerializable
{
    public function __construct(public int $duration, public bool $shouldAcknowledge)
    {

    }

    public function jsonSerialize(): array
    {
        return [
            'duration' => $this->duration,
            'shouldAcknowledge' => $this->shouldAcknowledge
        ];
    }


}
