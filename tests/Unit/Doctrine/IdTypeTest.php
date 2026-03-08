<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
 * - Returns correct SQL declaration, name, and comment hint
 */
#[CoversClass(IdType::class)]
final class IdTypeTest extends TestCase
{
    private IdType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new IdType();
        $this->platform = new MySQLPlatform();
    }

    #[Test]
    public function itConvertsPhpValueFromBinaryString(): void
    {
        $id = Id::new();
        $binary = $id->toBinary();

        $result = $this->type->convertToPHPValue($binary, $this->platform);

        $this->assertInstanceOf(Id::class, $result);
        $this->assertSame((string) $id, (string) $result);
    }

    #[Test]
    public function itReturnsNullPhpValueForNull(): void
    {
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    #[Test]
    public function itPassesThroughIdInstanceForPhpValue(): void
    {
        $id = Id::new();

        $result = $this->type->convertToPHPValue($id, $this->platform);

        $this->assertSame($id, $result);
    }

    #[Test]
    public function itThrowsForNonStringNonNullPhpValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected string value from database');

        $this->type->convertToPHPValue(12345, $this->platform);
    }

    #[Test]
    public function itConvertsToDatabaseBinaryForId(): void
    {
        $id = Id::new();

        $result = $this->type->convertToDatabaseValue($id, $this->platform);

        $this->assertIsString($result);
        $this->assertSame(16, strlen($result));
        $this->assertSame($id->toBinary(), $result);
    }

    #[Test]
    public function itReturnsNullDatabaseValueForNull(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    #[Test]
    public function itThrowsForNonIdDatabaseValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected Id instance');

        $this->type->convertToDatabaseValue('not-an-id', $this->platform);
    }

    #[Test]
    public function itReturnsBinary16SqlDeclarationForMySql(): void
    {
        $this->assertSame('BINARY(16)', $this->type->getSQLDeclaration([], new MySQLPlatform()));
    }

    #[Test]
    public function itReturnsByteaSqlDeclarationForPostgreSql(): void
    {
        $this->assertSame('BYTEA', $this->type->getSQLDeclaration([], new PostgreSQLPlatform()));
    }

    #[Test]
    public function itReturnsIdBinaryAsName(): void
    {
        $this->assertSame('id_binary', $this->type->getName());
    }

    #[Test]
    public function itRequiresSqlCommentHint(): void
    {
        $this->assertTrue($this->type->requiresSQLCommentHint($this->platform));
    }

    #[Test]
    public function itHandlesResourceStreamForPhpValue(): void
    {
        $id = Id::new();
        $binary = $id->toBinary();

        // PostgreSQL bytea columns return PHP resource streams
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $binary);
        rewind($stream);

        $result = $this->type->convertToPHPValue($stream, $this->platform);

        $this->assertInstanceOf(Id::class, $result);
        $this->assertSame((string) $id, (string) $result);
    }
}
