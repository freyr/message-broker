<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp;

/**
 * Formats AMQP topology configuration into RabbitMQ definitions JSON.
 *
 * The output follows the RabbitMQ definitions format and can be imported
 * via the Management HTTP API or rabbitmqctl.
 */
final readonly class DefinitionsFormatter
{
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
     * @param array{exchanges: array<string, array{type: string, durable: bool, arguments: array<string, mixed>}>, queues: array<string, array{durable: bool, arguments: array<string, mixed>}>, bindings: array<int, array{exchange: string, queue: string, binding_key: string, arguments: array<string, mixed>}>} $topology
     */
    public function __construct(
        private array $topology,
    ) {}

    /**
     * Format topology as RabbitMQ definitions structure.
     *
     * @return array{exchanges: array<int, array<string, mixed>>, queues: array<int, array<string, mixed>>, bindings: array<int, array<string, mixed>>}
     */
    public function format(string $vhost = '/'): array
    {
        return [
            'exchanges' => $this->formatExchanges($vhost),
            'queues' => $this->formatQueues($vhost),
            'bindings' => $this->formatBindings($vhost),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatExchanges(string $vhost): array
    {
        $exchanges = [];

        foreach ($this->topology['exchanges'] as $name => $config) {
            $exchanges[] = [
                'name' => $name,
                'vhost' => $vhost,
                'type' => $config['type'],
                'durable' => $config['durable'],
                'auto_delete' => false,
                'internal' => false,
                'arguments' => (object) $config['arguments'],
            ];
        }

        return $exchanges;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatQueues(string $vhost): array
    {
        $queues = [];

        foreach ($this->topology['queues'] as $name => $config) {
            $arguments = $this->normaliseArguments($config['arguments']);

            $queues[] = [
                'name' => $name,
                'vhost' => $vhost,
                'durable' => $config['durable'],
                'auto_delete' => false,
                'arguments' => (object) $arguments,
            ];
        }

        return $queues;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatBindings(string $vhost): array
    {
        $bindings = [];

        foreach ($this->topology['bindings'] as $binding) {
            $bindings[] = [
                'source' => $binding['exchange'],
                'vhost' => $vhost,
                'destination' => $binding['queue'],
                'destination_type' => 'queue',
                'routing_key' => $binding['binding_key'],
                'arguments' => (object) $binding['arguments'],
            ];
        }

        return $bindings;
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
            if (isset($arguments[$key])) {
                $arguments[$key] = (int) $arguments[$key];
            }
        }

        return $arguments;
    }
}
