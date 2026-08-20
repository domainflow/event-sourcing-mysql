<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Schema;

use DomainFlow\EventSourcing\Interface\SchemaManagerInterface;
use PDO;
use RuntimeException;

/**
 * Runs the migrations this package ships, in dependency order.
 *
 * The files are the source of truth, not a copy of them: a consumer applying
 * them by hand and a consumer calling `ensureSchema()` get the same statements,
 * so the two cannot drift. That mattered before there was a runner — the test
 * helper once carried its own CREATE TABLE statements, and the suite went green
 * against a schema no consumer ran.
 *
 * Idempotency comes from the files themselves: every one is
 * `CREATE TABLE IF NOT EXISTS`. A second run is a no-op rather than an error,
 * which is what a rerun deploy step needs.
 */
final readonly class MySqlSchemaManager implements SchemaManagerInterface
{
    /**
     * Tables in creation order, mapped to the file that creates each.
     *
     * Dropping walks it backwards. Nothing here has a foreign key today, but
     * an order that only works in one direction is the kind of thing that is
     * free to get right now and expensive to discover later.
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

    public function __construct(
        private PDO $pdo,
        private ?string $migrationsPath = null
    ) {
    }

    public function ensureSchema(): void
    {
        foreach (array_keys(self::MIGRATIONS) as $table) {
            $this->pdo->exec($this->readMigration($table));
        }
    }

    public function dropSchema(): void
    {
        foreach (array_reverse(array_keys(self::MIGRATIONS)) as $table) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    /**
     * @return list<string>
     */
    public function describeSchema(): array
    {
        return array_map(
            static fn (string $table): string => sprintf('CREATE TABLE IF NOT EXISTS %s', $table),
            array_keys(self::MIGRATIONS)
        );
    }

    private function readMigration(
        string $table
    ): string {
        $path = ($this->migrationsPath ?? dirname(__DIR__, 3) . '/migrations') . '/' . self::MIGRATIONS[$table];
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read migration "%s".', $path));
        }

        return $sql;
    }
}
