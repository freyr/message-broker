<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis\Tests\Unit;

use LogicException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Response\Error;
use Predis\Response\ServerException;

/**
 * A Predis client with no connection: each command is recorded and answered
 * from a queue of replies. An ErrorReply is thrown as ServerException, as a
 * Predis client with the default options does; with $exceptions = false it
 * is returned as a Predis\Response\Error.
 */
final class RecordingPredis implements ClientInterface
{
    /** @var list<array{string, list<mixed>}> the command and its arguments */
    public array $calls = [];

    /** @param list<mixed> $replies */
    public function __construct(
        private array $replies,
        private readonly bool $exceptions = true,
    ) {}

    public function getCommandFactory()
    {
        throw new LogicException('Not used by the bridge');
    }

    public function getOptions()
    {
        throw new LogicException('Not used by the bridge');
    }

    public function connect()
    {
        throw new LogicException('Not used by the bridge');
    }

    public function disconnect()
    {
        throw new LogicException('Not used by the bridge');
    }

    public function getConnection()
    {
        throw new LogicException('Not used by the bridge');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new LogicException('Not used by the bridge');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new LogicException('Not used by the bridge');
    }

    /** @param array<mixed> $arguments */
    public function __call($method, $arguments)
    {
        $this->calls[] = [strtoupper((string) $method), array_values($arguments)];
        $reply = array_shift($this->replies);
        if ($reply instanceof ErrorReply) {
            if ($this->exceptions) {
                throw new ServerException($reply->message);
            }

            return new Error($reply->message);
        }

        return $reply;
    }
}
