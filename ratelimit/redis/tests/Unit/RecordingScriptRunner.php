<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Freyr\MessageBroker\RateLimit\Redis\RedisScriptRunner;

/** A script runner that records each call and gives a fixed reply. */
final class RecordingScriptRunner implements RedisScriptRunner
{
    /** @var list<array{sha1: string, script: string, keys: list<string>, args: list<string|int>}> */
    public array $calls = [];

    public function __construct(
        private readonly mixed $reply,
    ) {}

    public function run(string $sha1, string $script, array $keys, array $args): mixed
    {
        $this->calls[] = [
            'sha1' => $sha1,
            'script' => $script,
            'keys' => $keys,
            'args' => $args,
        ];

        return $this->reply;
    }
}
