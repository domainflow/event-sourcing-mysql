<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Setup;

use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcingMySQL\ProcessManager\MySqlProcessManagerStorage;
use DomainFlow\EventSourcingMySQL\Snapshot\MySqlSnapshotHistoryStorage;
use DomainFlow\EventSourcingMySQL\Snapshot\MySqlSnapshotStorage;
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;

trait DatabaseSetup
{
    use DBHelper;

    protected MySqlEventStorage $eventStorage;
    protected MySqlSnapshotStorage $snapshotStorage;
    protected MySqlSnapshotHistoryStorage $snapshotHistoryStorage;
    protected MySqlProcessManagerStorage $processManagerStorage;

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
    }

    public function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    protected function getStorage(): EventStorageInterface
    {
        return new MySqlEventStorage($this->getPdo());
    }

    protected function getSnapshotStorage(): SnapshotStorageInterface
    {
        return new MySqlSnapshotStorage($this->getPdo());
    }

    protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface
    {
        return new MySqlSnapshotHistoryStorage($this->getPdo());
    }

    protected function getProcessManagerStorage(): ProcessManagerStorageInterface
    {
        return new MySqlProcessManagerStorage($this->getPdo());
    }
}
