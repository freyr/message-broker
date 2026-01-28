<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Normalizer;

use Carbon\CarbonImmutable;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes and denormalizes CarbonImmutable objects.
 */
final readonly class CarbonImmutableNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param CarbonImmutable $data
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        return $data->toIso8601String();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CarbonImmutable;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): CarbonImmutable
    {
        if (!is_string($data)) {
            throw new \InvalidArgumentException('CarbonImmutable value must be a string');
        }

        return CarbonImmutable::parse($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): bool {
        return $type === CarbonImmutable::class && is_string($data);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            CarbonImmutable::class => true,
        ];
    }
}
