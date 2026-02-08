<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Command;

use Freyr\MessageBroker\Amqp\DefinitionsFormatter;
use Freyr\MessageBroker\Amqp\TopologyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Declares AMQP topology (exchanges, queues, bindings) from bundle configuration.
 *
 * Supports three execution modes: live declaration against RabbitMQ,
 * dry-run preview, and RabbitMQ definitions JSON export.
 */
#[AsCommand(
    name: 'message-broker:setup-amqp',
    description: 'Declare AMQP topology (exchanges, queues, bindings) from configuration',
)]
final class SetupAmqpTopologyCommand extends Command
{
    public function __construct(
        private readonly TopologyManager $topologyManager,
        private readonly DefinitionsFormatter $definitionsFormatter,
        private readonly ?string $defaultDsn = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dsn',
                null,
                InputOption::VALUE_REQUIRED,
                'AMQP connection DSN (default: MESSENGER_AMQP_DSN env)'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned actions without executing')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Output RabbitMQ definitions JSON instead of executing')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File path for --dump output (default: stdout)')
            ->addOption(
                'vhost',
                null,
                InputOption::VALUE_REQUIRED,
                'Override vhost for --dump (default: extracted from DSN)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isDryRun = (bool) $input->getOption('dry-run');
        $isDump = (bool) $input->getOption('dump');

        // Dry-run mode: show planned actions
        if ($isDryRun) {
            return $this->executeDryRun($io);
        }

        // Dump mode: output RabbitMQ definitions JSON
        if ($isDump) {
            return $this->executeDump($input, $output, $io);
        }

        // Default mode: declare topology against live RabbitMQ
        return $this->executeDeclare($input, $io);
    }

    private function executeDryRun(SymfonyStyle $io): int
    {
        $io->title('AMQP Topology — Dry Run');

        $actions = $this->topologyManager->dryRun();

        if ($actions === []) {
            $io->info('No topology configured.');

            return Command::SUCCESS;
        }

        foreach ($actions as $action) {
            $io->writeln(sprintf('  %s', $action));
        }

        $io->newLine();
        $io->info(sprintf('%d action(s) would be performed.', count($actions)));

        return Command::SUCCESS;
    }

    private function executeDump(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $vhost = $this->resolveVhost($input);
        $definitions = $this->definitionsFormatter->format($vhost);

        $json = json_encode($definitions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $outputPath = $input->getOption('output');
        if (is_string($outputPath) && $outputPath !== '') {
            $result = file_put_contents($outputPath, $json . "\n");
            if ($result === false) {
                $io->error(sprintf('Failed to write definitions to %s', $outputPath));

                return Command::FAILURE;
            }
            $io->success(sprintf('RabbitMQ definitions written to %s', $outputPath));
        } else {
            $output->writeln($json);
        }

        return Command::SUCCESS;
    }

    private function executeDeclare(InputInterface $input, SymfonyStyle $io): int
    {
        $io->title('AMQP Topology Setup');

        $dsn = $this->resolveDsn($input);
        if ($dsn === null) {
            $io->error('No AMQP DSN provided. Use --dsn or set MESSENGER_AMQP_DSN environment variable.');

            return Command::FAILURE;
        }

        try {
            $connection = $this->createConnection($dsn);
            $connection->connect();
            $channel = new \AMQPChannel($connection);
        } catch (\AMQPConnectionException) {
            $io->error('Failed to connect to RabbitMQ. Check DSN and network connectivity.');

            return Command::FAILURE;
        }

        $results = $this->topologyManager->declare($channel);

        if ($results === []) {
            $io->info('No topology configured.');
            $connection->disconnect();

            return Command::SUCCESS;
        }

        $hasErrors = false;
        foreach ($results as $result) {
            $label = match ($result['status']) {
                'created' => '<fg=green>[OK]</>',
                'error' => '<fg=red>[ERROR]</>',
            };

            $detail = $result['detail'] !== '' ? sprintf(' (%s)', $result['detail']) : '';
            $io->writeln(sprintf('  %s %s "%s"%s', $label, ucfirst($result['type']), $result['name'], $detail));

            if ($result['status'] === 'error') {
                $hasErrors = true;
            }
        }

        $connection->disconnect();

        $io->newLine();
        if ($hasErrors) {
            $io->warning('Topology setup completed with errors.');

            return Command::FAILURE;
        }

        $io->success('Topology setup completed successfully.');

        return Command::SUCCESS;
    }

    private function resolveDsn(InputInterface $input): ?string
    {
        $dsn = $input->getOption('dsn');
        if (is_string($dsn) && $dsn !== '') {
            return $dsn;
        }

        return $this->defaultDsn;
    }

    private function resolveVhost(InputInterface $input): string
    {
        $vhost = $input->getOption('vhost');
        if (is_string($vhost) && $vhost !== '') {
            return $vhost;
        }

        $dsn = $this->resolveDsn($input);
        if ($dsn !== null) {
            return $this->parseDsn($dsn)['vhost'];
        }

        return '/';
    }

    /**
     * Parse an AMQP DSN into connection credentials.
     *
     * @return array<string, string|int>
     */
    private function parseDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('Invalid AMQP DSN: "%s"', $this->sanitiseDsn($dsn)));
        }

        $credentials = [];

        if (isset($parsed['host'])) {
            $credentials['host'] = $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $credentials['port'] = $parsed['port'];
        }
        if (isset($parsed['user'])) {
            $credentials['login'] = urldecode($parsed['user']);
        }
        if (isset($parsed['pass'])) {
            $credentials['password'] = urldecode($parsed['pass']);
        }

        $vhost = '/';
        if (isset($parsed['path'])) {
            $path = urldecode(ltrim($parsed['path'], '/'));
            $vhost = $path !== '' ? $path : '/';
        }
        $credentials['vhost'] = $vhost;
        $credentials['connect_timeout'] = 10;
        $credentials['read_timeout'] = 10;

        return $credentials;
    }

    private function createConnection(string $dsn): \AMQPConnection
    {
        return new \AMQPConnection($this->parseDsn($dsn));
    }

    private function sanitiseDsn(string $dsn): string
    {
        return preg_replace('#://[^@]+@#', '://***:***@', $dsn) ?? $dsn;
    }
}
