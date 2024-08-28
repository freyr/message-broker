<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Message;

use JsonSerializable;
use PhpAmqpLib\Message\AMQPMessage;

abstract class MessageHandler
{
    abstract protected static function createMessage(array $body): JsonSerializable;
    abstract protected function handle($message): bool;

    public function __invoke(AMQPMessage $amqpMessage): void
    {
        try {
            $message = self::createMessage(
                json_decode(
                    $amqpMessage->getBody(),
                    true
                )
            );
            $status = $this->handle($message);
            if ($status) {
                $amqpMessage->ack();
            } else {
                $amqpMessage->nack();
            }
        } catch (\Throwable $exception) {
            $amqpMessage->nack();
            if ($this->shouldCrashOnError()) {
                throw $exception;
            }
        }
    }

    protected function shouldCrashOnError(): bool
    {
        return false;
    }
}
