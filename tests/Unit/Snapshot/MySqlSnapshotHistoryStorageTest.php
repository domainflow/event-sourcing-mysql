<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractSnapshotHistoryStorageTestCase;
use DomainFlow\EventSourcingMySQL\Snapshot\MySqlSnapshotHistoryStorage;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MySqlSnapshotHistoryStorage::class)]
final class MySqlSnapshotHistoryStorageTest extends AbstractSnapshotHistoryStorageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS snapshot_history");

        $pdo->exec("
        CREATE TABLE snapshot_history (
            aggregate_id VARCHAR(255) NOT NULL,
            version INT NOT NULL,
            occurred_on DATETIME(6) NOT NULL,
            state TEXT NOT NULL,
            PRIMARY KEY (aggregate_id, version)
            );
        ");

        // Insert intentionally corrupted snapshot history rows
        $pdo->exec("
            INSERT INTO snapshot_history (aggregate_id, version, occurred_on, state)
            VALUES ('corrupt-agg', 1, '2024-01-01 00:00:00', 'not-json')
        ");
        $pdo->exec("
            INSERT INTO snapshot_history (aggregate_id, version, occurred_on, state)
            VALUES ('invalid-date-agg', 1, '2024-01-01 00:00:00', '{\"foo\": \"bar\"}')
        ");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $pdo = $this->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS snapshot_history");
    }

    private function getPdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $db = getenv('DB_NAME') ?: 'event_sourcing_test';
        $user = getenv('DB_USER') ?: 'user';
        $pass = getenv('DB_PASS') ?: 'userpassword';

        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface
    {
        return new MySqlSnapshotHistoryStorage(
            $this->getPdo()
        );
    }
}
