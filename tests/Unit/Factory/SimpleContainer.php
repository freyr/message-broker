<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Factory;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Simple PSR-11 container implementation for testing.
 *
 * Wraps an array of services for use with Symfony Messenger's SendersLocator.
 */
final class SimpleContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly array $services,
    ) {
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class("Service {$id} not found") extends \Exception implements NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
