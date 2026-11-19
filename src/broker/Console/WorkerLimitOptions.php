<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Console;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The option helpers of the worker limit options, shared by
 * {@see RelayRunCommand} and {@see ConsumeCommand}.
 *
 * @internal
 */
final class WorkerLimitOptions
{
    public static function takesNoParameter(callable $callable): bool
    {
        return (new ReflectionFunction(Closure::fromCallable($callable)))->getNumberOfParameters() === 0;
    }

    /** An option with a value is a string, or null when it is absent. */
    public static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    public static function intOption(?string $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid value for --{$name}: '{$value}' — expected an integer, 1 or more");
        }

        return (int) $value;
    }

    public static function memoryOption(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            return ByteSize::toBytes($value);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Invalid value for --memory-limit: {$e->getMessage()}", previous: $e);
        }
    }
}
