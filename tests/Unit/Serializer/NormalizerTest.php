<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\Normalizer\CarbonImmutableNormalizer;
use Freyr\MessageBroker\Serializer\Normalizer\IdNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for IdNormalizer and CarbonImmutableNormalizer.
 *
 * Tests that each normalizer:
 * - Round-trips correctly (normalize then denormalize preserves value)
 */
#[CoversClass(IdNormalizer::class)]
#[CoversClass(CarbonImmutableNormalizer::class)]
final class NormalizerTest extends TestCase
{
    public function testIdNormalizerRoundTripPreservesValue(): void
    {
        $normalizer = new IdNormalizer();
        $id = Id::new();

        $normalized = $normalizer->normalize($id);
        $denormalized = $normalizer->denormalize($normalized, Id::class);

        $this->assertInstanceOf(Id::class, $denormalized);
        $this->assertSame((string) $id, (string) $denormalized);
    }

    public function testCarbonImmutableNormalizerRoundTripPreservesTimestamp(): void
    {
        $normalizer = new CarbonImmutableNormalizer();
        $timestamp = CarbonImmutable::parse('2026-02-20T10:30:00+00:00');

        $normalized = $normalizer->normalize($timestamp);
        $denormalized = $normalizer->denormalize($normalized, CarbonImmutable::class);

        $this->assertInstanceOf(CarbonImmutable::class, $denormalized);
        $this->assertTrue($timestamp->equalTo($denormalized), 'Denormalized timestamp should equal original');
    }
}
