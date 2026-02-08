<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp;

use AMQPChannel;
use AMQPExchange;
use AMQPExchangeException;
use AMQPQueue;
use AMQPQueueException;
use Psr\Log\LoggerInterface;

/**
 * Manages AMQP topology declaration from configuration.
 *
 * Declares exchanges, queues, and bindings against a live RabbitMQ
 * instance using the ext-amqp PHP extension.
 */
final readonly class TopologyManager
{
    private const EXCHANGE_TYPE_MAP = [
        'direct' => AMQP_EX_TYPE_DIRECT,
        'fanout' => AMQP_EX_TYPE_FANOUT,
        'topic' => AMQP_EX_TYPE_TOPIC,
        'headers' => AMQP_EX_TYPE_HEADERS,
    ];

    /**
     * Queue arguments that must be integers for RabbitMQ.
     */
    private const INTEGER_ARGUMENTS = [
        'x-message-ttl',
        'x-max-length',
        'x-max-length-bytes',
        'x-max-priority',
        'x-expires',
        'x-delivery-limit',
    ];

    /**
     * @param array{
     *     exchanges: array<string, array{
     *      type: string,
     *      durable: bool,
     *      arguments: array<string, mixed>}>,
     *     queues: array<string, array{
     *      durable: bool,
     *      arguments: array<string, mixed>}>,
     *     bindings: array<int, array{
     *      exchange: string,
     *      queue: string,
     *      binding_key: string,
     *      arguments: array<string, mixed>}>
     * } $topology
     */
    public function __construct(
        private array $topology,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Declare the full topology against a live RabbitMQ instance.
     *
     * @return array<int, array{
     *     type: string,
     *     name: string,
     *     status: string,
     *     detail: string}>
     */
    public function declare(AMQPChannel $channel): array
    {
        $results = [];

        foreach ($this->topology['exchanges'] as $name => $config) {
            $results[] = $this->declareExchange($channel, $name, $config);
        }

        // Declare queues
        foreach ($this->topology['queues'] as $name => $config) {
            $results[] = $this->declareQueue($channel, $name, $config);
        }

        // Create bindings
        foreach ($this->topology['bindings'] as $binding) {
            $results[] = $this->declareBinding($channel, $binding);
        }

        return $results;
    }

    /**
     * Return planned actions without connecting to RabbitMQ.
     *
     * @return array<int, string>
     */
    public function dryRun(): array
    {
        $actions = [];

        foreach ($this->topology['exchanges'] as $name => $config) {
            $actions[] = sprintf(
                'Declare exchange "%s" (type: %s, durable: %s)',
                $name,
                $config['type'],
                $config['durable'] ? 'yes' : 'no',
            );
        }

        foreach ($this->topology['queues'] as $name => $config) {
            $actions[] = sprintf('Declare queue "%s" (durable: %s)', $name, $config['durable'] ? 'yes' : 'no');
        }

        foreach ($this->topology['bindings'] as $binding) {
            $bindingKey = $binding['binding_key'] !== '' ? $binding['binding_key'] : '(empty)';
            $actions[] = sprintf(
                'Bind queue "%s" to exchange "%s" with binding key "%s"',
                $binding['queue'],
                $binding['exchange'],
                $bindingKey,
            );
        }

        return $actions;
    }

    /**
     * Normalise queue arguments to ensure correct types for RabbitMQ.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normaliseArguments(array $arguments): array
    {
        foreach (self::INTEGER_ARGUMENTS as $key) {
            if (isset($arguments[$key]) && is_numeric($arguments[$key])) {
                $arguments[$key] = (int) $arguments[$key];
            }
        }

        return $arguments;
    }

    /**
     * @param array{
     *     type: string,
     *     durable: bool,
     *     arguments: array<string, mixed>} $config
     *
     * @return array{
     *     type: string,
     *     name: string,
     *     status: string,
     *     detail: string}
     */
    private function declareExchange(AMQPChannel $channel, string $name, array $config): array
    {
        try {
            $exchange = new AMQPExchange($channel);
            $exchange->setName($name);
            $exchange->setType(self::EXCHANGE_TYPE_MAP[$config['type']]);
            $exchange->setFlags($config['durable'] ? AMQP_DURABLE : AMQP_NOPARAM);

            if ($config['arguments'] !== []) {
                /** @var array<string, bool|float|int|string|null> $exchangeArgs */
                $exchangeArgs = $config['arguments'];
                $exchange->setArguments($exchangeArgs);
            }

            $exchange->declareExchange();

            $this->logger?->info('Declared exchange', [
                'name' => $name,
                'type' => $config['type'],
            ]);

            return [
                'type' => 'exchange',
                'name' => $name,
                'status' => 'created',
                'detail' => $config['type'],
            ];
        } catch (AMQPExchangeException $e) {
            $this->logger?->warning('Failed to declare exchange', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                'type' => 'exchange',
                'name' => $name,
                'status' => 'error',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array{
     *     durable: bool,
     *     arguments: array<string, mixed>} $config
     *
     * @return array{
     *     type: string,
     *     name: string,
     *     status: string,
     *     detail: string}
     */
    private function declareQueue(AMQPChannel $channel, string $name, array $config): array
    {
        try {
            $queue = new AMQPQueue($channel);
            $queue->setName($name);
            $queue->setFlags($config['durable'] ? AMQP_DURABLE : AMQP_NOPARAM);

            $arguments = $this->normaliseArguments($config['arguments']);
            if ($arguments !== []) {
                /** @var array<string, bool|float|int|string|null> $queueArgs */
                $queueArgs = $arguments;
                $queue->setArguments($queueArgs);
            }

            $queue->declareQueue();

            $this->logger?->info('Declared queue', [
                'name' => $name,
            ]);

            return [
                'type' => 'queue',
                'name' => $name,
                'status' => 'created',
                'detail' => '',
            ];
        } catch (AMQPQueueException $e) {
            $this->logger?->warning('Failed to declare queue', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                'type' => 'queue',
                'name' => $name,
                'status' => 'error',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array{
     *     exchange: string,
     *     queue: string,
     *     binding_key: string,
     *     arguments: array<string, mixed>} $binding
     *
     * @return array{
     *     type: string,
     *     name: string,
     *     status: string,
     *     detail: string}
     */
    private function declareBinding(AMQPChannel $channel, array $binding): array
    {
        $label = sprintf('%s → %s', $binding['exchange'], $binding['queue']);

        try {
            $queue = new AMQPQueue($channel);
            $queue->setName($binding['queue']);

            $queue->bind($binding['exchange'], $binding['binding_key'], $binding['arguments']);

            $this->logger?->info('Created binding', [
                'exchange' => $binding['exchange'],
                'queue' => $binding['queue'],
                'binding_key' => $binding['binding_key'],
            ]);

            return [
                'type' => 'binding',
                'name' => $label,
                'status' => 'created',
                'detail' => $binding['binding_key'],
            ];
        } catch (AMQPQueueException $e) {
            $this->logger?->warning('Failed to create binding', [
                'binding' => $label,
                'error' => $e->getMessage(),
            ]);

            return [
                'type' => 'binding',
                'name' => $label,
                'status' => 'error',
                'detail' => $e->getMessage(),
            ];
        }
    }
}
