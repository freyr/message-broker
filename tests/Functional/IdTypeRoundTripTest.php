<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\DBAL\Types\Type;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Doctrine\Type\IdType;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Functional test for IdType binary UUID v7 storage and retrieval against real MySQL.
 *
 * Uses a dedicated test table to verify that BINARY(16) storage
 * correctly round-trips UUID v7 values without corruption.
 */
#[CoversClass(IdType::class)]
final class IdTypeRoundTripTest extends FunctionalDatabaseTestCase
{
    private const TABLE = 'id_type_round_trip_test';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        self::$connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                id BINARY(16) NOT NULL PRIMARY KEY,
                label VARCHAR(50) NULL
            ) ENGINE=InnoDB',
            self::TABLE
        ));
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

    public function testBinaryStorageAndRetrieval(): void
    {
        $original = Id::new();
        $type = Type::getType(IdType::NAME);
        $platform = self::$connection->getDatabasePlatform();

        self::$connection->insert(self::TABLE, [
            'id' => $type->convertToDatabaseValue($original, $platform),
            'label' => 'test',
        ]);

        $raw = self::$connection->fetchOne(sprintf('SELECT id FROM %s WHERE label = ?', self::TABLE), ['test']);

        $restored = $type->convertToPHPValue($raw, $platform);

        $this->assertInstanceOf(Id::class, $restored);
        $this->assertTrue($original->sameAs($restored), 'Binary UUID v7 should round-trip correctly');
    }

    public function testMultipleIdsRoundTrip(): void
    {
        $type = Type::getType(IdType::NAME);
        $platform = self::$connection->getDatabasePlatform();
        $ids = [];

        for ($i = 0; $i < 5; ++$i) {
            $id = Id::new();
            $ids[$i] = $id;

            self::$connection->insert(self::TABLE, [
                'id' => $type->convertToDatabaseValue($id, $platform),
                'label' => 'item-'.$i,
            ]);
        }

        $rows = self::$connection->fetchAllAssociative(
            sprintf('SELECT id, label FROM %s ORDER BY label', self::TABLE)
        );

        $this->assertCount(5, $rows);

        foreach ($rows as $index => $row) {
            $restored = $type->convertToPHPValue($row['id'], $platform);
            $this->assertInstanceOf(Id::class, $restored);
            $this->assertTrue(
                $ids[$index]->sameAs($restored),
                sprintf('UUID at index %d should round-trip correctly', $index)
            );
        }
    }
}
