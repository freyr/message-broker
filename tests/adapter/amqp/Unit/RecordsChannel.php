<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Unit;

use Freyr\MessageBroker\Serializer\MetadataHeader;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\MockObject\Stub;

/**
 * A recording AMQP channel for tests without a broker: every call that the
 * code under test makes is stored in a property that the test asserts.
 */
trait RecordsChannel
{
    private const string QUEUE = 'orders';

    /** @var list<AMQPMessage> */
    private array $pending = [];

    private bool $timeoutWhenEmpty = false;

    /** @var list<int> */
    private array $acked = [];

    /** @var list<array{message: AMQPMessage, exchange: string, routingKey: string}> */
    private array $published = [];

    /** @var list<array<int, mixed>> */
    private array $declared = [];

    /** @var list<mixed> */
    private array $waitTimeouts = [];

    /** @var list<mixed> */
    private array $qosCalls = [];

    private int $confirmSelects = 0;

    private int $nackHandlers = 0;

    /** @var list<mixed> */
    private array $pendingAckTimeouts = [];

    private ?\Closure $nackHandler = null;

    private bool $nackOnConfirm = false;

    private bool $confirmTimesOut = false;

    /** @var list<list<mixed>> basic_cancel, basic_reject and basic_nack, in the order of the calls */
    private array $returnCalls = [];

    /** @var array<string, \Throwable> a method name => the error that the call throws */
    private array $failures = [];

    private string $consumedQueue = '';

    private ?\Closure $callback = null;

    private AMQPChannel&Stub $channel;

    private function setUpChannel(): void
    {
        $this->channel = $this->createStub(AMQPChannel::class);

        $this->channel->method('basic_qos')->willReturnCallback(function (...$args): void {
            $this->failIfConfigured('basic_qos');
            $this->qosCalls[] = $args;
        });
        $this->channel->method('basic_consume')->willReturnCallback(function (...$args): string {
            $this->failIfConfigured('basic_consume');
            foreach ($args as $arg) {
                if ($arg instanceof \Closure) {
                    $this->callback = $arg;
                }
            }
            $this->consumedQueue = (string) ($args[0] ?? '');

            return 'ctag';
        });
        $this->channel->method('is_consuming')->willReturnCallback(
            fn (): bool => $this->pending !== [] || $this->timeoutWhenEmpty,
        );
        $this->channel->method('wait')->willReturnCallback(function (...$args) {
            $this->waitTimeouts[] = $args;
            $this->failIfConfigured('wait');
            if ($this->pending === []) {
                throw new AMQPTimeoutException('idle');
            }
            ($this->callback)(array_shift($this->pending));

            return null;
        });
        $this->channel->method('basic_cancel')->willReturnCallback(function (...$args): void {
            $this->returnCalls[] = ['basic_cancel', ...$args];
            $this->failIfConfigured('basic_cancel');
        });
        $this->channel->method('basic_reject')->willReturnCallback(function (...$args): void {
            $this->returnCalls[] = ['basic_reject', ...$args];
            $this->failIfConfigured('basic_reject');
        });
        $this->channel->method('basic_nack')->willReturnCallback(function (...$args): void {
            $this->returnCalls[] = ['basic_nack', ...$args];
            $this->failIfConfigured('basic_nack');
        });
        $this->channel->method('basic_ack')->willReturnCallback(function (int $tag): void {
            $this->acked[] = $tag;
        });
        $this->channel->method('confirm_select')->willReturnCallback(function (): void {
            ++$this->confirmSelects;
        });
        $this->channel->method('set_nack_handler')->willReturnCallback(function (\Closure $handler): void {
            ++$this->nackHandlers;
            $this->nackHandler = $handler;
        });
        $this->channel->method('queue_declare')->willReturnCallback(function (...$args): void {
            $this->declared[] = $args;
        });
        $this->channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message, string $exchange, string $routingKey): void {
                $this->published[] = [
                    'message' => $message,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                ];
            },
        );
        $this->channel->method('wait_for_pending_acks')->willReturnCallback(function ($timeout): void {
            $this->pendingAckTimeouts[] = $timeout;
            if ($this->confirmTimesOut) {
                throw new AMQPTimeoutException('confirm timeout');
            }
            if ($this->nackOnConfirm && $this->nackHandler !== null) {
                ($this->nackHandler)();
            }
        });
    }

    private function failIfConfigured(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }

    /** @param array<string, mixed> $extraHeaders */
    private function delivery(
        int $tag = 1,
        string $messageId = 'm-1',
        array $extraHeaders = [],
        string $body = '{"order_id":"o-1"}',
        bool $withEnvelope = true,
        ?string $propertyMessageId = null,
    ): AMQPMessage {
        $headers = ($withEnvelope ? MetadataHeader::envelope($messageId, 'order.placed', 1_765_476_000_123) : [])
            + $extraHeaders;
        $properties = [
            'application_headers' => new AMQPTable($headers),
        ];
        if ($propertyMessageId !== null) {
            $properties['message_id'] = $propertyMessageId;
        }
        $message = new AMQPMessage($body, $properties);
        $message->setChannel($this->channel);
        $message->setDeliveryInfo($tag, false, '', self::QUEUE);

        return $message;
    }
}
