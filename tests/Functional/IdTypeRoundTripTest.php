<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional test for IdType binary ULID storage and retrieval against a real database (MySQL or PostgreSQL).
 *
 * Uses a dedicated test table to verify that binary storage (BINARY(16) / BYTEA)
 * correctly round-trips ULID values without corruption.
 */
#[CoversClass(IdType::class)]
final class IdTypeRoundTripTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'id_type_round_trip_test';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $schemaManager = self::$connection->createSchemaManager();

        if ($schemaManager->tablesExist([self::TABLE])) {
            $schemaManager->dropTable(self::TABLE);
        }

        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => true,
        ]);
        $table->addColumn('label', Types::STRING, [
            'length' => 50,
            'notnull' => false,
        ]);
        $table->setPrimaryKey(['id']);

        $schemaManager->createTable($table);
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::$connection->executeStatement(sprintf('TRUNCATE TABLE %s', self::TABLE));
    }

    #[Test]
    public function itRoundTripsBinaryStorageCorrectly(): void
    {
        $original = Id::new();

        self::$connection->insert(self::TABLE, [
            'id' => $original->toBinary(),
            'label' => 'test',
        ], [
            'id' => ParameterType::BINARY,
        ]);

        $type = Type::getType(IdType::NAME);
        $platform = self::$connection->getDatabasePlatform();
        $raw = self::$connection->fetchOne(sprintf('SELECT id FROM %s WHERE label = ?', self::TABLE), ['test']);

        $restored = $type->convertToPHPValue($raw, $platform);

        $this->assertInstanceOf(Id::class, $restored);
        $this->assertTrue($original->sameAs($restored), 'Binary ULID should round-trip correctly');
    }

    #[Test]
    public function itRoundTripsMultipleIds(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; ++$i) {
            $id = Id::new();
            $ids[$i] = $id;

            self::$connection->insert(self::TABLE, [
                'id' => $id->toBinary(),
                'label' => 'item-'.$i,
            ], [
                'id' => ParameterType::BINARY,
            ]);
        }

        $type = Type::getType(IdType::NAME);
        $platform = self::$connection->getDatabasePlatform();
        $rows = self::$connection->fetchAllAssociative(
            sprintf('SELECT id, label FROM %s ORDER BY label', self::TABLE)
        );

        $this->assertCount(5, $rows);

        foreach ($rows as $index => $row) {
            $restored = $type->convertToPHPValue($row['id'], $platform);
            $this->assertInstanceOf(Id::class, $restored);
            $this->assertTrue(
                $ids[$index]->sameAs($restored),
                sprintf('ULID at index %d should round-trip correctly', $index)
            );
        }
    }
}
