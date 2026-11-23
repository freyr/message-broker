<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Unit;

use Freyr\MessageBroker\Storage\PostgreSql\PdoTransaction;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoTransactionTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function pdo(?PDOException $beginError = null, ?PDOException $commitError = null, ?PDOException $rollBackError = null): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () use ($beginError): bool {
            $this->calls[] = 'begin';

            return $beginError === null ? true : throw $beginError;
        });
        $pdo->method('commit')->willReturnCallback(function () use ($commitError): bool {
            $this->calls[] = 'commit';

            return $commitError === null ? true : throw $commitError;
        });
        $pdo->method('rollBack')->willReturnCallback(function () use ($rollBackError): bool {
            $this->calls[] = 'rollback';

            return $rollBackError === null ? true : throw $rollBackError;
        });

        return $pdo;
    }

    public function testEachCallIsForwardedToThePdoConnection(): void
    {
        $transaction = new PdoTransaction($this->pdo());

        $transaction->begin();
        $transaction->commit();
        $transaction->begin();
        $transaction->rollBack();

        self::assertSame(['begin', 'commit', 'begin', 'rollback'], $this->calls);
    }

    public function testBeginPropagatesTheExceptionOfThePdo(): void
    {
        $error = new PDOException('db down');
        $transaction = new PdoTransaction($this->pdo(beginError: $error));

        try {
            $transaction->begin();
            self::fail('begin() must propagate the PDO exception');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testCommitPropagatesTheExceptionOfThePdo(): void
    {
        $error = new PDOException('no active transaction');
        $transaction = new PdoTransaction($this->pdo(commitError: $error));

        try {
            $transaction->commit();
            self::fail('commit() must propagate the PDO exception');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testRollBackPropagatesTheExceptionOfThePdo(): void
    {
        $error = new PDOException('connection gone');
        $transaction = new PdoTransaction($this->pdo(rollBackError: $error));

        try {
            $transaction->rollBack();
            self::fail('rollBack() must propagate the PDO exception');
        } catch (PDOException $e) {
            self::assertSame($error, $e);
        }
    }
}
