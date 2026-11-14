<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use Freyr\MessageBroker\Message;

final class Unserializable extends Message
{
    public static function create(): self
    {
        // NAN cannot be JSON-encoded — must be rejected at produce time (D17).
        return new self(key: 'k', name: 'broken.message', payload: [
            'value' => NAN,
        ]);
    }
}
