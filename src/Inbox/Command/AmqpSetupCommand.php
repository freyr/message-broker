<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Inbox\Command;

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
use Symfony\Component\Yaml\Yaml;

/**
 * AMQP Setup Command.
 *
 * Declares exchanges, queues, and bindings from external configuration file.
 * Supports:
 * - RabbitMQ native definitions format (JSON) - imported via Management API
 * - Custom YAML/JSON format - setup via AMQP protocol
 */
#[AsCommand(
    name: 'message-broker:amqp-setup',
    description: 'Setup AMQP exchanges, queues, and bindings from configuration file',
)]
final class AmqpSetupCommand extends Command
{
    private readonly string $amqpHost;
    private readonly int $amqpPort;
    private readonly string $amqpUser;
    private readonly string $amqpPassword;
    private readonly string $amqpVhost;
    private readonly int $managementPort;

    public function __construct(
        string $amqpDsn = 'amqp://guest:guest@localhost:5672/%2f',
        int $managementPort = 15672,
    ) {
        parent::__construct();

        // Parse AMQP DSN
        $parsed = parse_url($amqpDsn);
        $this->amqpHost = $parsed['host'] ?? 'localhost';
        $this->amqpPort = $parsed['port'] ?? 5672;
        $this->amqpUser = $parsed['user'] ?? 'guest';
        $this->amqpPassword = $parsed['pass'] ?? 'guest';
        $this->amqpVhost = isset($parsed['path']) ? urldecode(ltrim($parsed['path'], '/')) : '/';
        $this->managementPort = $managementPort;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file (YAML or JSON)'
            )
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

        // Get config file path
        $configFile = $input->getOption('config');
        if (!is_string($configFile) || $configFile === '') {
            $io->error('Configuration file is required. Use --config=/path/to/config.yaml');
            return Command::FAILURE;
        }

        if (!file_exists($configFile)) {
            $io->error(sprintf('Configuration file not found: %s', $configFile));
            return Command::FAILURE;
        }

        if (!is_readable($configFile)) {
            $io->error(sprintf('Configuration file is not readable: %s', $configFile));
            return Command::FAILURE;
        }

        $io->title('AMQP Infrastructure Setup');
        $io->writeln(sprintf('Config file: <info>%s</info>', $configFile));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be applied');
        }

        try {
            // Load and parse configuration
            $config = $this->loadConfiguration($configFile, $io);

            // Check if it's a RabbitMQ native definitions format
            if ($this->isNativeDefinitionsFormat($config)) {
                return $this->importNativeDefinitions($config, $io, $dryRun);
            }

            // Custom format - setup via AMQP protocol
            return $this->setupViaAmqp($config, $io, $dryRun);
        } catch (Exception $e) {
            $io->error(sprintf('Error: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfiguration(string $configFile, SymfonyStyle $io): array
    {
        $contents = file_get_contents($configFile);
        if ($contents === false) {
            throw new RuntimeException('Failed to read configuration file');
        }

        // Detect format by extension
        $extension = strtolower(pathinfo($configFile, PATHINFO_EXTENSION));

        if ($extension === 'yaml' || $extension === 'yml') {
            $io->writeln('Format: <info>YAML</info>');
            return Yaml::parse($contents);
        }

        if ($extension === 'json') {
            $io->writeln('Format: <info>JSON</info>');
            $config = json_decode($contents, true);
            if (!is_array($config)) {
                throw new RuntimeException('Invalid JSON format');
            }
            return $config;
        }

        throw new RuntimeException(sprintf('Unsupported file format: %s (use .yaml, .yml, or .json)', $extension));
    }

    /**
     * Check if config is RabbitMQ native definitions format.
     *
     * @param array<string, mixed> $config
     */
    private function isNativeDefinitionsFormat(array $config): bool
    {
        // RabbitMQ definitions have specific root keys
        $nativeKeys = ['rabbit_version', 'rabbitmq_version', 'vhosts', 'users', 'permissions'];

        foreach ($nativeKeys as $key) {
            if (isset($config[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Import RabbitMQ native definitions via Management API.
     *
     * @param array<string, mixed> $config
     */
    private function importNativeDefinitions(array $config, SymfonyStyle $io, bool $dryRun): int
    {
        $io->section('RabbitMQ Native Definitions Format Detected');

        if ($dryRun) {
            $io->warning('Native definitions import via Management API does not support dry-run');
            $io->note('Would import definitions to RabbitMQ Management API');
            return Command::SUCCESS;
        }

        $io->writeln('Importing via RabbitMQ Management API...');

        // Build Management API URL
        $url = sprintf(
            'http://%s:%d/api/definitions',
            $this->amqpHost,
            $this->managementPort
        );

        // Use curl to import definitions
        $jsonPayload = json_encode($config);
        if ($jsonPayload === false) {
            throw new RuntimeException('Failed to encode configuration to JSON');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $this->amqpUser, $this->amqpPassword));

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $io->success('Definitions imported successfully via Management API');
            return Command::SUCCESS;
        }

        $io->error(sprintf('Management API returned HTTP %d: %s', $httpCode, is_string($response) ? $response : ''));
        $io->note('Ensure RabbitMQ Management plugin is enabled: rabbitmq-plugins enable rabbitmq_management');

        return Command::FAILURE;
    }

    /**
     * Setup AMQP infrastructure from custom configuration format.
     *
     * @param array<string, mixed> $config
     */
    private function setupViaAmqp(array $config, SymfonyStyle $io, bool $dryRun): int
    {
        $io->section('Custom Configuration Format');

        $connection = new AMQPStreamConnection(
            $this->amqpHost,
            $this->amqpPort,
            $this->amqpUser,
            $this->amqpPassword,
            $this->amqpVhost
        );

        $channel = $connection->channel();

        $io->section('Exchanges');
        $this->setupExchanges($channel, $config, $io, $dryRun);

        $io->section('Queues');
        $this->setupQueues($channel, $config, $io, $dryRun);

        $io->section('Bindings');
        $this->setupBindings($channel, $config, $io, $dryRun);

        $channel->close();
        $connection->close();

        $io->success('AMQP setup completed successfully');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setupExchanges(AMQPChannel $channel, array $config, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $exchanges */
        $exchanges = $config['exchanges'] ?? [];

        if (empty($exchanges)) {
            $io->writeln('<comment>No exchanges configured</comment>');
            return;
        }

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
     * @param array<string, mixed> $config
     */
    private function setupQueues(AMQPChannel $channel, array $config, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $queues */
        $queues = $config['queues'] ?? [];

        if (empty($queues)) {
            $io->writeln('<comment>No queues configured</comment>');
            return;
        }

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

    /**
     * @param array<string, mixed> $config
     */
    private function setupBindings(AMQPChannel $channel, array $config, SymfonyStyle $io, bool $dryRun): void
    {
        /** @var array<int, array<string, mixed>> $bindings */
        $bindings = $config['bindings'] ?? [];

        if (empty($bindings)) {
            $io->writeln('<comment>No bindings configured</comment>');
            return;
        }

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
