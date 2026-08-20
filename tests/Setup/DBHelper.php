<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Setup;

use PDO;
use RuntimeException;

trait DBHelper
{
    /**
     * The shipped migration behind each table the suite needs.
     *
     * @var array<string, string>
     */
    private const array MIGRATIONS = [
        'events' => 'events-table.sql',
        'snapshots' => 'snapshot-table.sql',
        'snapshot_history' => 'snapshot-history-table.sql',
        'process_manager_states' => 'process-manager-states-table.sql',
        'outbox' => 'outbox-table.sql',
        'outbox_dead' => 'outbox-dead-table.sql',
    ];

    public function getPdo(): PDO
    {
        return $this->newPdo(PDO::class);
    }

    /**
     * Connects using the same settings as getPdo(), but as the given PDO
     * subclass, so a test can observe what the storage does to the connection.
     *
     * @template T of PDO
     * @param class-string<T> $pdoClass
     * @return T
     */
    public function newPdo(string $pdoClass = PDO::class): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $db = getenv('DB_NAME') ?: 'event_sourcing_test';
        $user = getenv('DB_USER') ?: 'user';
        $pass = getenv('DB_PASS') ?: 'userpassword';

        $pdo = new $pdoClass("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function setupEventsTable(): void
    {
        $this->createTableFromMigration('events');
    }

    public function setupSnapshotTable(): void
    {
        $this->createTableFromMigration('snapshots');
    }

    public function setupSnapshotHistoryTable(): void
    {
        $this->createTableFromMigration('snapshot_history');
    }

    public function setupProcessManagerStatesTable(): void
    {
        $this->createTableFromMigration('process_manager_states');
    }

    public function setupOutboxTable(): void
    {
        $this->createTableFromMigration('outbox');
        $this->createTableFromMigration('outbox_dead');
    }

    /**
     * Builds a table by running the migration this package actually ships.
     *
     * The helper used to carry its own CREATE TABLE statements, and they had
     * drifted: `events` had `event_id` as the primary key and no `id` column,
     * `snapshot_history` had no unique constraint on (aggregate_id, version),
     * and every `aggregate_id` was VARCHAR(255) instead of VARCHAR(64). The
     * suite was therefore green against a schema no consumer runs. Reading the
     * shipped file is the only arrangement in which that cannot silently
     * recur.
     *
     * @param string $table
     * @return void
     */
    private function createTableFromMigration(
        string $table
    ): void {
        $file = self::MIGRATIONS[$table] ?? throw new RuntimeException(
            sprintf('No migration is mapped for table "%s".', $table)
        );

        $path = dirname(__DIR__, 2) . '/migrations/' . $file;
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read migration "%s".', $path));
        }

        $pdo = $this->getPdo();
        $pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $table));
        $pdo->exec($sql);
    }

    protected function setUpDatabase(): void
    {
        $this->setupEventsTable();
        $this->setupSnapshotTable();
        $this->setupSnapshotHistoryTable();
        $this->setupProcessManagerStatesTable();
        $this->setupOutboxTable();
    }

    protected function tearDownDatabase(): void
    {
        $this->tearDownEventsTable();
        $this->tearDownSnapshotTable();
        $this->tearDownSnapshotHistoryTable();
        $this->tearDownProcessManagerStatesTable();
        $this->tearDownOutboxTable();
    }
    public function tearDownEventsTable(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS events");
    }

    public function tearDownSnapshotTable(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS snapshots");
    }

    public function tearDownSnapshotHistoryTable(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS snapshot_history");
    }

    public function tearDownProcessManagerStatesTable(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS process_manager_states");
    }

    public function tearDownOutboxTable(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS outbox");
        $this->getPdo()->exec("DROP TABLE IF EXISTS outbox_dead");
    }
}
