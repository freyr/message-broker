<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Normalizer;

use Freyr\Identity\Id;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes and denormalizes Id objects (UUID v7).
 */
final readonly class IdNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param Id $data
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        return $data->__toString();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Id;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Id
    {
        if (!is_string($data)) {
            throw new \InvalidArgumentException('Id value must be a string');
        }

        return Id::fromString($data);
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
        return $type === Id::class && is_string($data);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Id::class => true,
        ];
    }
}
