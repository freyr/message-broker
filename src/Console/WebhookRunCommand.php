<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Closure;
use Freyr\MessageBroker\Webhook\DeliveryLoop;
use Freyr\MessageBroker\Webhook\DeliveryLoopConfig;
use Freyr\MessageBroker\Worker\WorkerLimits;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running webhook delivery worker: one {@see DeliveryLoop} serves all
 * lanes, and any number of identical workers can run. The lane filter, the
 * batch size and the row limit for each lane are fields of the
 * {@see DeliveryLoopConfig} that the loop takes in its constructor, so the
 * application registers a factory and a base config:
 *
 *     new WebhookRunCommand(
 *         static fn (DeliveryLoopConfig $config) => new DeliveryLoop($store, $sender, $subscriptions, config: $config),
 *         new DeliveryLoopConfig(leaseMs: 60_000),
 *     );
 *
 * The options --lane (repeatable), --batch-size and --lane-limit replace their
 * fields of the base config; each other field stays. The command builds one
 * {@see WorkerLimits} from --limit (delivered rows), --time-limit and
 * --memory-limit and gives it to DeliveryLoop::run(). A limit stop ends the
 * run like SIGTERM does, and the command exits with 0: the container must
 * start again after each exit, exit code 0 included (a Kubernetes
 * Deployment, restartPolicy Always).
 */
#[AsCommand(name: 'message-broker:webhook:run', description: 'Run the webhook delivery loop for all lanes or the given lanes (blocks)')]
final class WebhookRunCommand extends Command
{
    /** @var Closure(DeliveryLoopConfig): DeliveryLoop */
    private readonly Closure $loopFactory;

    /** @param callable(DeliveryLoopConfig): DeliveryLoop $loopFactory */
    public function __construct(
        callable $loopFactory,
        private readonly DeliveryLoopConfig $config = new DeliveryLoopConfig(),
    ) {
        $this->loopFactory = Closure::fromCallable($loopFactory);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'lane',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Claim only this lane (a subscription id); repeat it for more lanes. Default: the lane filter of the config',
        );
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'The most rows of one claim, all lanes together, 1 or more');
        $this->addOption('lane-limit', null, InputOption::VALUE_REQUIRED, 'The most rows of one lane in one claim, 1 or more');
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after this many delivered rows, 1 or more (checked after each pass)',
        );
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds, 1 or more');
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop at this memory use, <number>[K|M|G] (for example 128M); keep it below the PHP memory_limit',
        );
        $this->addOption(
            'require-signals',
            null,
            InputOption::VALUE_NONE,
            'Fail if ext-pcntl (graceful shutdown) is unavailable',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->config($input);
            $limits = new WorkerLimits(
                maxMessages: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'limit'), 'limit'),
                maxSeconds: WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'time-limit'), 'time-limit'),
                maxMemoryBytes: WorkerLimitOptions::memoryOption(WorkerLimitOptions::stringOption($input, 'memory-limit')),
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::INVALID;
        }

        $lanes = $config->laneFilter === null ? 'all lanes' : 'the lanes '.implode(', ', $config->laneFilter);
        $output->writeln("<info>Delivering webhooks for {$lanes} — SIGTERM/SIGINT to stop.</info>");
        $warning = SignalSupport::warning(extension_loaded('pcntl'));
        if ($warning !== null) {
            if ($input->getOption('require-signals')) {
                $output->writeln("<error>{$warning}</error>");

                return Command::FAILURE;
            }
            $output->writeln("<comment>{$warning}</comment>");
        }

        ($this->loopFactory)($config)->run($limits);

        return Command::SUCCESS;
    }

    /**
     * The base config with the fields of the options that the operator gave.
     * Each other field, also one that a later release adds, keeps its base value:
     * the fields are the promoted constructor parameters.
     *
     * @throws InvalidArgumentException when an option value is not valid
     */
    private function config(InputInterface $input): DeliveryLoopConfig
    {
        /** @var list<string> $lanes */
        $lanes = $input->getOption('lane');
        $overrides = array_filter([
            'batchSize' => WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'batch-size'), 'batch-size'),
            'limitForEachLane' => WorkerLimitOptions::intOption(WorkerLimitOptions::stringOption($input, 'lane-limit'), 'lane-limit'),
            'laneFilter' => $lanes === [] ? null : array_values(array_unique($lanes)),
        ], static fn (mixed $value): bool => $value !== null);

        // The values come from a DeliveryLoopConfig, so each has the type of its parameter;
        // get_object_vars() is typed array<string, mixed>.
        // @phpstan-ignore argument.type
        return new DeliveryLoopConfig(...[...get_object_vars($this->config), ...$overrides]);
    }
}
