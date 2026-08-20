<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Snapshot;

use DomainFlow\EventSourcingCore\Provider\Unit\AbstractSnapshotStorageTestCase;
use DomainFlow\EventSourcingMySQL\Snapshot\MySqlSnapshotStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MySqlSnapshotStorage::class)]
final class MySqlSnapshotStorageTest extends AbstractSnapshotStorageTestCase
{
    use DatabaseSetup;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS snapshots");
        $pdo->exec("DROP TABLE IF EXISTS snapshot_history");

        $pdo->exec("
        CREATE TABLE snapshots (
            aggregate_id VARCHAR(255) NOT NULL PRIMARY KEY,
            version INT NOT NULL,
            occurred_on DATETIME(6) NOT NULL,
            state TEXT NOT NULL,
            snapshot_class VARCHAR(255) NOT NULL
        );
    ");

        $pdo->exec("
        CREATE TABLE snapshot_history (
            aggregate_id VARCHAR(255) NOT NULL,
            version INT NOT NULL,
            occurred_on DATETIME(6) NOT NULL,
            state TEXT NOT NULL,
            PRIMARY KEY (aggregate_id, version)
        );
    ");

        $pdo->exec("
        INSERT INTO snapshots (
            aggregate_id, version, occurred_on, state, snapshot_class
        ) VALUES (
            'json-corrupt-id',
            1,
            '2024-01-01 00:00:00',
            'INVALID_JSON',
            'DomainFlow\\\\EventSourcing\\\\Snapshot\\\\GenericSnapshot'
        );
    ");

        // Invalid occurred_on (null forces fallback)
        $pdo->exec("
        INSERT INTO snapshots (
            aggregate_id, version, occurred_on, state, snapshot_class
        ) VALUES (
            'bad-occurred-id',
            1,
            '2024-01-01 00:00:00',
            '{\"x\": \"y\"}',
            'DomainFlow\\\\EventSourcing\\\\Snapshot\\\\GenericSnapshot'
        );
    ");
    }
}
