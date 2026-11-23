<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use Redis;

/**
 * A \Redis with no connection: each evalsha() and eval() call is recorded and
 * answered from a queue of replies. A reply that is an error string (an
 * instance of ErrorReply) makes the call return false and sets the last
 * error, as phpredis does for an error reply of the server.
 */
final class RecordingPhpRedis extends Redis
{
    /** @var list<array{string, string, list<mixed>, int}> the command, the SHA1 or the script, the arguments and the number of keys */
    public array $calls = [];

    /** @var list<string> each clearLastError() call, in order with the commands */
    public array $log = [];

    private ?string $lastError = null;

    /** @param list<mixed> $replies */
    public function __construct(
        private array $replies,
        ?string $lastError = null,
    ) {
        $this->lastError = $lastError;
    }

    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        return $this->reply('EVALSHA', $sha1, $args, $num_keys);
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->reply('EVAL', $script, $args, $num_keys);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function clearLastError(): bool
    {
        $this->log[] = 'clearLastError';
        $this->lastError = null;

        return true;
    }

    /** @param array<mixed> $args */
    private function reply(string $command, string $script, array $args, int $numKeys): mixed
    {
        $this->calls[] = [$command, $script, array_values($args), $numKeys];
        $this->log[] = $command;
        $reply = array_shift($this->replies);
        if ($reply instanceof ErrorReply) {
            $this->lastError = $reply->message;

            return false;
        }

        return $reply;
    }
}
