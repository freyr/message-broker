<?php

declare(strict_types=1);

namespace Freyr\Messenger\Inbox\Command;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AMQP Setup Command.
 *
 * Declares exchanges, queues, and bindings based on configuration.
 * Detects differences between config and runtime state.
 */
#[AsCommand(
    name: 'inbox:amqp-setup',
    description: 'Setup AMQP exchanges, queues, and bindings declaratively',
)]
final class AmqpSetupCommand extends Command
{
    /**
     * AMQP Configuration.
     *
     * Define your exchanges, queues, and bindings here.
     *
     * Setup:
     * - Exchange: fsm.client_requests (topic) - receives all client request events from FSM
     * - Queue: fsm.cr.inbox - SLA inbox queue for incident messages
     * - Binding: fsm.cr.incident.* - only incident-related messages routed to SLA
     *
     * Message routing examples:
     * - fsm.cr.incident.created → routed to fsm.cr.inbox ✓
     * - fsm.cr.incident.updated → routed to fsm.cr.inbox ✓
     * - fsm.cr.support.created  → NOT routed (different type) ✗
     *
     * @var array<string, mixed>
     */
    private array $config = [
        'exchanges' => [
            [
                'name' => 'fsm.client_requests',
                'type' => 'topic',
                'passive' => false,
                'durable' => true,
                'auto_delete' => false,
            ],
        ],
        'queues' => [
            [
                'name' => 'fsm.cr.inbox',
                'passive' => false,
                'durable' => true,
                'exclusive' => false,
                'auto_delete' => false,
            ],
        ],
        'bindings' => [
            [
                'queue' => 'fsm.cr.inbox',
                'exchange' => 'fsm.client_requests',
                'routing_key' => 'fsm.cr.incident.*',
            ],
        ],
    ];

    public function __construct(
        private readonly string $amqpHost = 'localhost',
        private readonly int $amqpPort = 5672,
        private readonly string $amqpUser = 'guest',
        private readonly string $amqpPassword = 'guest',
        private readonly string $amqpVhost = '/',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be changed without applying'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $cleanup = (bool) $input->getOption('cleanup');

        $io->title('AMQP Infrastructure Setup');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be applied');
        }

        try {
            $connection = new AMQPStreamConnection(
                $this->amqpHost,
                $this->amqpPort,
                $this->amqpUser,
                $this->amqpPassword,
                $this->amqpVhost
            );

            $channel = $connection->channel();

            $io->section('Exchanges');
            $this->setupExchanges($channel, $io, $dryRun);

            $io->section('Queues');
            $this->setupQueues($channel, $io, $dryRun);

            $io->section('Bindings');
            $this->setupBindings($channel, $io, $dryRun);

            $channel->close();
            $connection->close();

            $io->success('AMQP setup completed successfully');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error(sprintf('AMQP Error: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * @param AMQPChannel $channel
     */
    private function setupExchanges($channel, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $exchanges */
        $exchanges = $this->config['exchanges'] ?? [];

        foreach ($exchanges as $exchange) {
            $name = (string) $exchange['name'];
            $type = (string) ($exchange['type'] ?? 'topic');
            $passive = (bool) ($exchange['passive'] ?? false);
            $durable = (bool) ($exchange['durable'] ?? true);
            $autoDelete = (bool) ($exchange['auto_delete'] ?? false);

            try {
                // Check if exchange exists
                $channel->exchange_declare($name, $type, true, $durable, $autoDelete);
                $io->writeln(sprintf('✓ Exchange <info>%s</info> already exists', $name));
            } catch (AMQPProtocolChannelException $e) {
                // Exchange doesn't exist or type mismatch
                if (!$dryRun) {
                    $channel = $this->reopenChannel($channel);
                    $channel->exchange_declare($name, $type, $passive, $durable, $autoDelete);
                    $io->writeln(sprintf('✅ Created exchange <info>%s</info> (type: %s)', $name, $type));
                } else {
                    $io->writeln(sprintf('➕ Would create exchange <info>%s</info> (type: %s)', $name, $type));
                }
            }
        }
    }

    /**
     * @param AMQPChannel $channel
     */
    private function setupQueues($channel, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $queues */
        $queues = $this->config['queues'] ?? [];

        foreach ($queues as $queue) {
            $name = (string) $queue['name'];
            $passive = (bool) ($queue['passive'] ?? false);
            $durable = (bool) ($queue['durable'] ?? true);
            $exclusive = (bool) ($queue['exclusive'] ?? false);
            $autoDelete = (bool) ($queue['auto_delete'] ?? false);

            try {
                // Check if queue exists
                $channel->queue_declare($name, true, $durable, $exclusive, $autoDelete);
                $io->writeln(sprintf('✓ Queue <info>%s</info> already exists', $name));
            } catch (AMQPProtocolChannelException $e) {
                // Queue doesn't exist
                if (!$dryRun) {
                    $channel = $this->reopenChannel($channel);
                    $channel->queue_declare($name, $passive, $durable, $exclusive, $autoDelete);
                    $io->writeln(sprintf('✅ Created queue <info>%s</info>', $name));
                } else {
                    $io->writeln(sprintf('➕ Would create queue <info>%s</info>', $name));
                }
            }
        }
    }

    private function setupBindings(AMQPChannel $channel, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $bindings */
        $bindings = $this->config['bindings'] ?? [];

        foreach ($bindings as $binding) {
            $queue = (string) $binding['queue'];
            $exchange = (string) $binding['exchange'];
            $routingKey = (string) ($binding['routing_key'] ?? '');

            if (!$dryRun) {
                // AMQP protocol doesn't provide a way to check if binding exists
                // Just create it (it's idempotent)
                $channel->queue_bind($queue, $exchange, $routingKey);
                $io->writeln(sprintf(
                    '✅ Bound queue <info>%s</info> to exchange <info>%s</info> (routing key: %s)',
                    $queue,
                    $exchange,
                    $routingKey ?: '(empty)'
                ));
            } else {
                $io->writeln(sprintf(
                    '➕ Would bind queue <info>%s</info> to exchange <info>%s</info> (routing key: %s)',
                    $queue,
                    $exchange,
                    $routingKey ?: '(empty)'
                ));
            }
        }
    }

    private function reopenChannel(AMQPChannel $channel): AMQPChannel
    {
        try {
            $connection = $channel->getConnection();
            return $connection->channel();
        } catch (Exception $e) {
            throw new RuntimeException('Failed to reopen channel: ' . $e->getMessage());
        }
    }
}
