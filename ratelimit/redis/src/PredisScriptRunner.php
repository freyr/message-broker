<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\RateLimit\Redis;

use Predis\ClientInterface;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;

/**
 * The bridge for a Predis client. With the default options Predis throws
 * ServerException for an error reply; with the option "exceptions" off it
 * returns a Predis\Response\Error. The bridge handles both: on NOSCRIPT of
 * the EVALSHA it calls EVAL with the script, and each other error throws
 * {@see RedisScriptError}.
 */
final readonly class PredisScriptRunner implements RedisScriptRunner
{
    public function __construct(
        private ClientInterface $client,
    ) {}

    public function run(string $sha1, string $script, array $keys, array $args): mixed
    {
        $arguments = array_map(strval(...), [...$keys, ...$args]);
        $result = $this->call('evalsha', $sha1, count($keys), $arguments);
        if ($result instanceof ErrorInterface) {
            if ($result->getErrorType() !== 'NOSCRIPT') {
                throw self::error($result);
            }
            $result = $this->call('eval', $script, count($keys), $arguments);
            if ($result instanceof ErrorInterface) {
                throw self::error($result);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $arguments
     *
     * @return mixed the reply, or the error reply as ErrorInterface
     */
    private function call(string $command, string $script, int $numberOfKeys, array $arguments): mixed
    {
        try {
            return $this->client->{$command}($script, $numberOfKeys, ...$arguments);
        } catch (ServerException $e) {
            return $e;
        }
    }

    private static function error(ErrorInterface $error): RedisScriptError
    {
        return RedisScriptError::fromReply($error->getMessage(), $error instanceof ServerException ? $error : null);
    }
}
