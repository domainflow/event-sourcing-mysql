<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Setup;

use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the arrangement, not the code: the suite must exercise the schema
 * consumers actually run.
 *
 * `tests/Setup/DBHelper.php` used to carry its own CREATE TABLE statements,
 * and they had drifted from `migrations/*.sql` — a different primary key on
 * `events`, a missing unique constraint on `snapshot_history`, and wider
 * `aggregate_id` columns throughout. Every one of those makes the suite
 * greener than the shipped schema deserves. The helper now runs the migration
 * files; these assertions fail if anyone puts hand-written DDL back.
 */
#[CoversNothing]
final class ShippedSchemaTest extends TestCase
{
    use DatabaseSetup;

    public function test_theEventsTableHasTheAutoIncrementIdTheMigrationShips(): void
    {
        $id = $this->column('events', 'id');

        $this->assertNotNull($id, 'events.id is the global position cursor — the suite must have it.');
        $this->assertStringContainsString('auto_increment', (string) $id['Extra']);
        $this->assertSame(
            'bigint unsigned',
            (string) $id['Type'],
            'A 32-bit id runs out at ~2.1 billion events, and an event store is the one table that only ever grows.'
        );
    }

    public function test_theEventsTableUsesTheShippedAggregateIdWidth(): void
    {
        $aggregateId = $this->column('events', 'aggregate_id');

        $this->assertNotNull($aggregateId);
        $this->assertSame(
            'varchar(64)',
            (string) $aggregateId['Type'],
            'A wider column in tests than in production hides every id that is too long to store.'
        );
    }

    public function test_theSnapshotHistoryTableRejectsADuplicateVersion(): void
    {
        $indexes = $this->getPdo()->query('SHOW INDEX FROM snapshot_history')->fetchAll(PDO::FETCH_ASSOC);

        $unique = array_filter(
            $indexes,
            static fn (array $index): bool => (string) $index['Key_name'] === 'uq_aggregate_version'
                && (int) $index['Non_unique'] === 0
        );

        $this->assertCount(
            2,
            $unique,
            'The shipped table is unique on (aggregate_id, version). Without it the suite lets a snapshot history hold two rows for the same version.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Identifier columns compare bytes.
     *
     * The server default is case- and accent-insensitive, which is right for
     * text a human wrote and wrong for an opaque identifier: two aggregate ids
     * differing only in case then collide in the unique index and match each
     * other in every WHERE clause. The contract case in
     * AbstractEventStorageTestCase asserts the behaviour; this asserts the
     * schema property it rests on, because a table created from a stale copy
     * of the migration would fail the first in a way that is hard to read.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function identifierColumns(): iterable
    {
        yield 'events.aggregate_id' => ['events', 'aggregate_id'];
        yield 'events.event_id' => ['events', 'event_id'];
        yield 'snapshots.aggregate_id' => ['snapshots', 'aggregate_id'];
        yield 'snapshot_history.aggregate_id' => ['snapshot_history', 'aggregate_id'];
        yield 'process_manager_states.process_id' => ['process_manager_states', 'process_id'];
        yield 'outbox.aggregate_id' => ['outbox', 'aggregate_id'];
    }

    #[DataProvider('identifierColumns')]
    public function test_identifierColumnsCompareBytesRatherThanText(
        string $table,
        string $column
    ): void {
        $statement = $this->getPdo()->prepare(sprintf('SHOW FULL COLUMNS FROM %s WHERE Field = :column', $table));
        $statement->execute(['column' => $column]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, sprintf('%s.%s is missing from the shipped schema.', $table, $column));
        $this->assertSame(
            'ascii_bin',
            (string) $row['Collation'],
            sprintf(
                '%s.%s holds an opaque identifier. A case-insensitive collation makes two different ids the same id.',
                $table,
                $column
            )
        );
    }

    private function column(
        string $table,
        string $column
    ): ?array {
        $statement = $this->getPdo()->prepare(sprintf('SHOW COLUMNS FROM %s WHERE Field = :column', $table));
        $statement->execute(['column' => $column]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
