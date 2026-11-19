<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use DateTimeImmutable;
use Freyr\MessageBroker\DeadLetter\DeadLetterFilter;
use Freyr\MessageBroker\Time\EpochMillis;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The shared filter options of dlq:list, dlq:stats, dlq:purge and
 * dlq:replay --all: configure() adds them to a command, filter() builds the
 * DeadLetterFilter. A command catches the InvalidArgumentException of
 * filter(), prints its message and returns Command::INVALID.
 */
final readonly class DeadLetterFilterOptions
{
    private const string DATE_TIME = '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-]\d{2}:\d{2})\z/';

    private const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public static function configure(Command $command): void
    {
        $time = 'a duration (30s, 15m, 24h, 7d: now minus the duration) or an ISO 8601 date-time with an offset or Z';
        $command
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Only this message name')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source (work queue)')
            ->addOption('error', null, InputOption::VALUE_REQUIRED, 'Only this error class (full class name)')
            ->addOption('message-id', null, InputOption::VALUE_REQUIRED, 'Only this message id')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, "Only failures at or after this time: {$time}")
            ->addOption('until', null, InputOption::VALUE_REQUIRED, "Only failures before this time: {$time}")
            ->addOption('min-attempts', null, InputOption::VALUE_REQUIRED, 'Only rows with at least this many attempts')
            ->addOption('replayed', null, InputOption::VALUE_NONE, 'Only replayed rows')
            ->addOption('not-replayed', null, InputOption::VALUE_NONE, 'Only rows that are not replayed')
            ->addOption('replayable', null, InputOption::VALUE_NONE, 'Only rows with the canonical document')
            ->addOption('not-replayable', null, InputOption::VALUE_NONE, 'Only rows with a raw body');
    }

    /** @throws InvalidArgumentException when an option has an invalid value, or both flags of a pair are set */
    public static function filter(InputInterface $input): DeadLetterFilter
    {
        return new DeadLetterFilter(
            messageName: self::string($input, 'name'),
            source: self::string($input, 'source'),
            errorClass: self::string($input, 'error'),
            messageId: self::string($input, 'message-id'),
            failedFromMs: self::time($input, 'since'),
            failedUntilMs: self::time($input, 'until'),
            minAttempts: self::minAttempts($input),
            replayed: self::flagPair($input, 'replayed', 'not-replayed'),
            replayable: self::flagPair($input, 'replayable', 'not-replayable'),
        );
    }

    private static function string(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) ? $value : null;
    }

    private static function time(InputInterface $input, string $option): ?int
    {
        $value = self::string($input, $option);
        if ($value === null) {
            return null;
        }
        try {
            return EpochMillis::now() - Duration::toMilliseconds($value);
        } catch (InvalidArgumentException) {
        }
        $dateTime = self::dateTime($value);
        if ($dateTime === null) {
            throw new InvalidArgumentException(
                "Invalid time '{$value}' for --{$option}: expected a duration (30s, 15m, 24h, 7d) or an ISO 8601 date-time with an offset or Z",
            );
        }

        return EpochMillis::fromDateTime($dateTime);
    }

    /** An ISO 8601 date-time with an offset or Z; null for any other value, also an impossible date. */
    private static function dateTime(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::DATE_TIME, $value, $match) !== 1) {
            return null;
        }
        [, $date, $hoursMinutes, $seconds, $fraction, $offset] = $match;
        $normalized = sprintf(
            '%sT%s:%s.%s%s',
            $date,
            $hoursMinutes,
            $seconds === '' ? '00' : $seconds,
            str_pad($fraction, 6, '0'),
            $offset === 'Z' ? '+00:00' : $offset,
        );
        $dateTime = DateTimeImmutable::createFromFormat(self::FORMAT, $normalized);

        // createFromFormat() rolls an impossible date over (February 30 is March 2): the round trip refuses it.
        return $dateTime !== false && $dateTime->format(self::FORMAT) === $normalized ? $dateTime : null;
    }

    private static function minAttempts(InputInterface $input): ?int
    {
        $value = self::string($input, 'min-attempts');
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[1-9]\d*\z/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid --min-attempts '{$value}': expected an integer of 1 or more");
        }

        return (int) $value;
    }

    private static function flagPair(InputInterface $input, string $yes, string $no): ?bool
    {
        $isYes = $input->getOption($yes) === true;
        $isNo = $input->getOption($no) === true;
        if ($isYes && $isNo) {
            throw new InvalidArgumentException("Use --{$yes} or --{$no}, not both.");
        }

        return $isYes ? true : ($isNo ? false : null);
    }
}
