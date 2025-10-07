<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Command;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Freyr\MessageBroker\Inbox\Message\InboxEventMessage;
use Freyr\MessageBroker\Inbox\Stamp\MessageIdStamp;
use Freyr\MessageBroker\Inbox\Stamp\MessageNameStamp;
use Freyr\MessageBroker\Inbox\Stamp\SourceQueueStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Consume AMQP to Messenger Command.
 *
 * Consumes messages from AMQP queue and dispatches to Messenger inbox transport.
 * Messenger handles deduplication via custom doctrine-inbox transport.
 */
#[AsCommand(
    name: 'inbox:ingest',
    description: 'Consume AMQP messages and dispatch to Messenger inbox (with deduplication)',
)]
final class AmqpInboxIngestCommand extends Command
{
    private readonly string $amqpHost;
    private readonly int $amqpPort;
    private readonly string $amqpUser;
    private readonly string $amqpPassword;
    private readonly string $amqpVhost;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        string $amqpDsn = 'amqp://guest:guest@localhost:5672/%2f',
    ) {
        parent::__construct();

        // Parse AMQP DSN
        $parsed = parse_url($amqpDsn);
        $this->amqpHost = $parsed['host'] ?? 'localhost';
        $this->amqpPort = $parsed['port'] ?? 5672;
        $this->amqpUser = $parsed['user'] ?? 'guest';
        $this->amqpPassword = $parsed['pass'] ?? 'guest';
        $this->amqpVhost = isset($parsed['path']) ? urldecode(ltrim($parsed['path'], '/')) : '/';
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'queue',
                'q',
                InputOption::VALUE_REQUIRED,
                'AMQP queue name to consume from'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $queueName */
        $queueName = $input->getOption('queue');

        if (!$queueName) {
            $io->error('Queue name is required. Use --queue=<queue-name>');
            return Command::FAILURE;
        }

        $io->title('AMQP to Messenger Consumer');
        $io->info(sprintf('Queue: %s', $queueName));
        $io->info('Messages will be dispatched to Messenger inbox with deduplication...');
        $io->newLine();

        try {
            $connection = new AMQPStreamConnection(
                $this->amqpHost,
                $this->amqpPort,
                $this->amqpUser,
                $this->amqpPassword,
                $this->amqpVhost
            );

            $channel = $connection->channel();

            $io->success(sprintf('Connected to queue: %s', $queueName));

            // Consume messages
            $callback = function (AMQPMessage $msg) use ($io, $queueName): void {
                $this->handleMessage($msg, $io, $queueName);
            };

            $channel->basic_qos(0, 1, false); // Prefetch 1 message
            $channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                $callback
            );

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
            $io->error(sprintf('AMQP Error: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function handleMessage(
        AMQPMessage $msg,
        SymfonyStyle $io,
        string $sourceQueue,
    ): void {
        try {
            $body = $msg->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                $io->error('Invalid message format: not an array');
                $msg->nack();

                return;
            }

            /** @var array<string, mixed> $data */
            $data = $decoded;

            // Extract message name (required for routing)
            if (!isset($data['message_name']) || !is_string($data['message_name'])) {
                $io->error('Missing or invalid message_name in message');
                $msg->nack();
                return;
            }
            $messageName = $data['message_name'];

            // Extract payload
            if (!isset($data['payload']) || !is_array($data['payload'])) {
                $io->error('Missing or invalid payload in message');
                $msg->nack();
                return;
            }
            /** @var array<string, mixed> $payload */
            $payload = $data['payload'];

            // Extract message_id for deduplication (REQUIRED)
            if (!isset($data['message_id']) || !is_string($data['message_id'])) {
                $io->error('Missing or invalid message_id in message - message_id is required for deduplication');
                $msg->nack();
                return;
            }
            $messageId = $data['message_id'];

            // Create an inbox message and dispatch to Messenger
            $inboxMessage = new InboxEventMessage(
                $messageName,
                $payload,
                $messageId,
                $sourceQueue
            );

            // Dispatch to Messenger inbox transport
            // The custom doctrine-inbox transport will handle deduplication
            $this->messageBus->dispatch($inboxMessage, [
                new TransportNamesStamp(['inbox']),
                new MessageNameStamp($messageName),
                new MessageIdStamp($messageId),
                new SourceQueueStamp($sourceQueue),
            ]);

            $io->writeln(sprintf(
                '✅ Dispatched: %s (message_id: %s)',
                $messageName,
                $messageId
            ));

            // Always ACK - deduplication is handled by Messenger transport
            $msg->ack();
        } catch (\Throwable $e) {
            $io->error(sprintf('Error processing message: %s', $e->getMessage()));
            $msg->nack();
        }
    }

}
