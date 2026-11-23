<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlSchemaInstaller;

final class PostgreSqlSchemaInstallerTest extends FunctionalTestCase
{
    public function testInstallCreatesTheTablesAndThePartitionsOfTheRange(): void
    {
        $this->dropEverything();

        (new PostgreSqlSchemaInstaller(self::$pdo))->install(2);

        foreach (['outbox_messages', 'message_deduplication', 'dead_letters'] as $table) {
            self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = '{$table}'"), $table);
        }
        self::assertSame(3, self::fetchInt("SELECT COUNT(*) FROM pg_inherits WHERE inhparent = 'outbox_messages'::regclass"), 'today + 2 days ahead');
    }

    public function testInstallTwiceIsSafe(): void
    {
        $this->dropEverything();
        $installer = new PostgreSqlSchemaInstaller(self::$pdo);

        $installer->install(1);
        $installer->install(1);
        $installer->install(3);

        self::assertSame(4, self::fetchInt("SELECT COUNT(*) FROM pg_inherits WHERE inhparent = 'outbox_messages'::regclass"));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'uq_dead_letters_open'"));
    }

    public function testTheOpenPairIndexIsUniqueValidAndPartialOnTheRowsThatAreNotReplayed(): void
    {
        $this->dropEverything();

        (new PostgreSqlSchemaInstaller(self::$pdo))->install(0);

        $statement = self::$pdo->query(<<<'SQL'
            SELECT i.indisunique, i.indisvalid, pg_get_indexdef(i.indexrelid) AS definition
            FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
            WHERE c.relname = 'uq_dead_letters_open'
            SQL);
        self::assertNotFalse($statement);
        $index = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([
            'indisunique' => true,
            'indisvalid' => true,
            'definition' => 'CREATE UNIQUE INDEX uq_dead_letters_open ON public.dead_letters USING btree (message_id, source) WHERE (replayed_at IS NULL)',
        ], $index);
    }

    public function testInstallKeepsExistingRows(): void
    {
        $installer = new PostgreSqlSchemaInstaller(self::$pdo);
        $installer->install(1);
        self::$pdo->exec("INSERT INTO message_deduplication (message_id, consumer, message_name, created_at) VALUES ('m', 'c', 'n', now())");

        $installer->install(1);

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM message_deduplication'));
    }

    public function testTheDumpedSqlBuildsTheSameSchemaOnAnEmptyDatabase(): void
    {
        $this->dropEverything();

        foreach ((new PostgreSqlSchemaInstaller(self::$pdo))->sql(1) as $statement) {
            self::$pdo->exec($statement);
        }

        self::assertSame(2, self::fetchInt("SELECT COUNT(*) FROM pg_inherits WHERE inhparent = 'outbox_messages'::regclass"));
        self::assertSame(1, self::fetchInt("SELECT COUNT(*) FROM pg_class WHERE relname = 'dead_letters'"));
    }

    private function dropEverything(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS dead_letters');
        self::$pdo->exec('DROP TABLE IF EXISTS message_deduplication');
        self::$pdo->exec('DROP TABLE IF EXISTS outbox_messages');
        $leftovers = self::$pdo->query("SELECT relname FROM pg_class WHERE relkind = 'r' AND relname ~ '^outbox_messages_p[0-9]{8}$'");
        self::assertNotFalse($leftovers);
        foreach ($leftovers->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            self::$pdo->exec(sprintf('DROP TABLE "%s"', (string) $name));
        }
    }
}
