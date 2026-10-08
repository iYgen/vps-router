<?php

namespace Tests;

use App\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTxnTest extends TestCase
{
    public function testTransactionCommits(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS txn_probe (v INTEGER)');
        $pdo->exec('DELETE FROM txn_probe');

        Database::transaction(function (\PDO $p) {
            $p->exec('INSERT INTO txn_probe (v) VALUES (1)');
            $p->exec('INSERT INTO txn_probe (v) VALUES (2)');
        });

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM txn_probe')->fetchColumn());
    }

    public function testTransactionRollsBackOnException(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS txn_probe (v INTEGER)');
        $pdo->exec('DELETE FROM txn_probe');

        try {
            Database::transaction(function (\PDO $p) {
                $p->exec('INSERT INTO txn_probe (v) VALUES (7)');
                throw new \RuntimeException('boom');
            });
            $this->fail('исключение должно было пробросить');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM txn_probe')->fetchColumn(), 'вставка откатилась');
        // После отката PDO не должен думать, что транзакция ещё открыта.
        $this->assertFalse($pdo->inTransaction());
    }

    public function testNestedTransactionRunsInline(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS txn_probe (v INTEGER)');
        $pdo->exec('DELETE FROM txn_probe');

        $result = Database::transaction(function () {
            // Вложенный вызов не открывает вторую транзакцию (иначе SQLite упадёт).
            return Database::transaction(fn(\PDO $p) => $p->exec('INSERT INTO txn_probe (v) VALUES (5)')) !== null ? 'ok' : 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM txn_probe')->fetchColumn());
    }

    public function testRetryReturnsValueAndRunsOnce(): void
    {
        $calls = 0;
        $val = Database::retry(function () use (&$calls) {
            $calls++;
            return 42;
        });
        $this->assertSame(42, $val);
        $this->assertSame(1, $calls, 'без блокировки — ровно один вызов');
    }

    public function testRetryRethrowsNonLockError(): void
    {
        $this->expectException(\LogicException::class);
        Database::retry(function () {
            throw new \LogicException('not a lock');
        });
    }
}
