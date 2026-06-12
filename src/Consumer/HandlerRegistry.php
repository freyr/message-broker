<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Consumer;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Explicit message_name → Binding map plus stage 2 → 3 denormalization
 * (symfony/serializer turns the payload section into the bound class).
 */
final readonly class HandlerRegistry
{
    /** @param array<string, Binding> $bindings keyed by message_name */
    public function __construct(
        private array $bindings,
        private DenormalizerInterface $denormalizer,
    ) {}

    public function bindingFor(string $messageName): ?Binding
    {
        return $this->bindings[$messageName] ?? null;
    }

    public function denormalize(IncomingMessage $incoming, Binding $binding): object
    {
        return $this->denormalizer->denormalize($incoming->payload, $binding->class);
    }
}
