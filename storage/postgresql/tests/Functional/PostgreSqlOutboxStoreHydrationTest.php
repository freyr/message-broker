<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Outbox\ClaimOutcome;
use Freyr\MessageBroker\Outbox\OutboxRecord;
use Freyr\MessageBroker\Storage\PostgreSql\PostgreSqlOutboxStore;
use Freyr\MessageBroker\Time\EpochMillis;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * How the store reads a claimed row: the attempts column accepts an integer
 * or a numeric string, and anything else fails the claim with a clear error
 * and rolls the claim back.
 */
final class PostgreSqlOutboxStoreHydrationTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function acceptedAttempts(): iterable
    {
        yield 'integer column' => ['3', 3];
        yield 'numeric string column' => ["'4'::text", 4];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedAttempts(): iterable
    {
        yield 'non-numeric string' => ["'abc'::text"];
        yield 'null' => ['NULL::int'];
        yield 'boolean' => ['true'];
    }

    #[DataProvider('acceptedAttempts')]
    public function testAttemptsColumnAcceptsAnIntegerOrANumericString(string $expression, int $expected): void
    {
        $this->insertRow();
        $seen = null;

        $store = $this->storeReadingAttemptsAs($expression);
        $published = $store->drainClaimed('hydration', 10, static function (array $claimed) use (&$seen): ClaimOutcome {
            $seen = $claimed[0]->attempts;

            return ClaimOutcome::published([$claimed[0]->id]);
        });

        self::assertSame(1, $published);
        self::assertSame($expected, $seen);
    }

    #[DataProvider('rejectedAttempts')]
    public function testAttemptsColumnThatIsNotAnIntegerFailsTheClaimAndRollsItBack(string $expression): void
    {
        $this->insertRow();
        $store = $this->storeReadingAttemptsAs($expression);

        try {
            $store->drainClaimed('hydration', 10, static fn (array $claimed): ClaimOutcome => ClaimOutcome::published([$claimed[0]->id]));
            self::fail('a bad attempts column must fail the claim');
        } catch (RuntimeException $error) {
            self::assertSame("Outbox column 'attempts' is not an integer", $error->getMessage());
        }

        self::assertSame(1, self::fetchInt('SELECT COUNT(*) FROM outbox_messages WHERE published_at IS NULL'));
    }

    private function insertRow(): void
    {
        (new PostgreSqlOutboxStore(self::$pdo))->insert(new OutboxRecord(
            id: '01900000-0000-7000-8000-000000000001',
            lane: 'hydration',
            messageName: 'order.placed',
            body: '{}',
            headers: [],
            createdAt: EpochMillis::now() - 1_000,
        ));
    }

    private function storeReadingAttemptsAs(string $expression): PostgreSqlOutboxStore
    {
        // The claim query selects `attempts`; the connection swaps that column
        // for the expression, so the store hydrates a deliberately bad value.
        [$dsn, $user, $password] = self::credentials();
        $pdo = new class($dsn, $user, $password, $expression) extends PDO {
            public function __construct(
                string $dsn,
                string $user,
                string $password,
                private readonly string $expression
            ) {
                parent::__construct($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return parent::prepare(str_replace(', attempts', ", {$this->expression} AS attempts", $query), $options);
            }
        };

        return new PostgreSqlOutboxStore($pdo);
    }
}
