<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for IdType Doctrine DBAL type.
 *
 * Tests that the type:
 * - Converts binary string to Id (PHP value)
 * - Returns null for null (both directions)
 * - Passes through Id instance unchanged
 * - Throws on invalid types (both directions)
 * - Converts Id to binary string (database value)
 */
#[CoversClass(IdType::class)]
final class IdTypeTest extends TestCase
{
    private IdType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new IdType();
        $this->platform = $this->createStub(AbstractPlatform::class);
    }

    public function testConvertToPHPValueReturnIdForBinaryString(): void
    {
        $id = Id::new();
        $binary = $id->toBinary();

        $result = $this->type->convertToPHPValue($binary, $this->platform);

        $this->assertInstanceOf(Id::class, $result);
        $this->assertSame((string) $id, (string) $result);
    }

    public function testConvertToPHPValueReturnsNullForNull(): void
    {
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testConvertToPHPValuePassesThroughIdInstance(): void
    {
        $id = Id::new();

        $result = $this->type->convertToPHPValue($id, $this->platform);

        $this->assertSame($id, $result);
    }

    public function testConvertToPHPValueThrowsForNonStringNonNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected string value from database');

        $this->type->convertToPHPValue(12345, $this->platform);
    }

    public function testConvertToDatabaseValueReturnsBinaryForId(): void
    {
        $id = Id::new();

        $result = $this->type->convertToDatabaseValue($id, $this->platform);

        $this->assertIsString($result);
        $this->assertSame(16, strlen($result));
        $this->assertSame($id->toBinary(), $result);
    }

    public function testConvertToDatabaseValueReturnsNullForNull(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    public function testConvertToDatabaseValueThrowsForNonId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected Id instance');

        $this->type->convertToDatabaseValue('not-an-id', $this->platform);
    }
}
