<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Serializer\Avro;

use Freyr\MessageBroker\Serializer\Avro\ConfluentFrame;
use Freyr\MessageBroker\Serializer\MalformedMessage;
use PHPUnit\Framework\TestCase;

final class ConfluentFrameTest extends TestCase
{
    public function testFramesWithMagicByteAndBigEndianSchemaId(): void
    {
        $framed = new ConfluentFrame(schemaId: 42, avroBytes: 'PAYLOAD')
            ->bytes();

        self::assertSame("\x00\x00\x00\x00\x2APAYLOAD", $framed);
    }

    public function testRoundTrip(): void
    {
        $frame = ConfluentFrame::parse(new ConfluentFrame(7, "\x01\x02\x03")->bytes());

        self::assertSame(7, $frame->schemaId);
        self::assertSame("\x01\x02\x03", $frame->avroBytes);
    }

    public function testEmptyPayloadIsValid(): void
    {
        $frame = ConfluentFrame::parse("\x00\x00\x00\x01\x00");

        self::assertSame(256, $frame->schemaId);
        self::assertSame('', $frame->avroBytes);
    }

    public function testTooShortInputIsMalformed(): void
    {
        $this->expectException(MalformedMessage::class);

        ConfluentFrame::parse("\x00\x00\x00");
    }

    public function testUnknownMagicByteIsMalformed(): void
    {
        $this->expectException(MalformedMessage::class);

        ConfluentFrame::parse("\x01\x00\x00\x00\x2APAYLOAD");
    }
}
