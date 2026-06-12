<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Freyr\MessageBroker\Serializer\Avro\AvroWireValidator;
use Freyr\MessageBroker\Serializer\Avro\FileSchemaStore;
use PHPUnit\Framework\TestCase;

final class AvroWireValidatorTest extends TestCase
{
    private function validator(): AvroWireValidator
    {
        return new AvroWireValidator(new FileSchemaStore([
            'order.placed' => __DIR__.'/../../../Fixtures/schemas/order_placed.avsc',
        ]));
    }

    public function testConformingPayloadPasses(): void
    {
        $this->validator()
            ->assertPublishable([
                'metadata' => [
                    'message_name' => 'order.placed',
                    'message_id' => '0197a3a4-7e3a-7e3a-8e3a-7e3a7e3a7e3a',
                    'created_at' => 1718000000000,
                ],
                'payload' => [
                    'order_id' => 'o-1',
                    'total_cents' => 100,
                ],
            ]);

        $this->expectNotToPerformAssertions();
    }

    public function testDocumentWithoutMetadataSectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/metadata and payload/');

        $this->validator()
            ->assertPublishable([
                'payload' => [
                    'order_id' => 'o-1',
                    'total_cents' => 100,
                ],
            ]);
    }

    public function testMetadataMissingIdOrCreatedAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/message_id/');

        $this->validator()
            ->assertPublishable([
                'metadata' => [
                    'message_name' => 'order.placed',
                    // message_id and created_at missing — the serializer
                    // would reject this document at relay time.
                ],
                'payload' => [
                    'order_id' => 'o-1',
                    'total_cents' => 100,
                ],
            ]);
    }

    public function testNonConformingPayloadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not conform/');

        $this->validator()
            ->assertPublishable([
                'metadata' => [
                    'message_name' => 'order.placed',
                    'message_id' => '0197a3a4-7e3a-7e3a-8e3a-7e3a7e3a7e3a',
                    'created_at' => 1718000000000,
                ],
                'payload' => [
                    'order_id' => 'o-1',
                    // total_cents missing — schema violation
                ],
            ]);
    }

    public function testUnmappedMessageNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()
            ->assertPublishable([
                'metadata' => [
                    'message_name' => 'order.unknown',
                    'message_id' => '0197a3a4-7e3a-7e3a-8e3a-7e3a7e3a7e3a',
                    'created_at' => 1718000000000,
                ],
                'payload' => [],
            ]);
    }
}
