<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Storage;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractEventStorageTestCase;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use DomainFlow\EventSourcingMySQL\Outbox\MySqlOutboxStorage;
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[CoversClass(MySqlEventStorage::class)]
#[UsesClass(MySqlOutboxStorage::class)]
class MySqlEventStorageTest extends AbstractEventStorageTestCase
{
    use DatabaseSetup;

    protected function getStorageWhoseWritesFailWithoutConflict(): EventStorageInterface
    {
        // A missing table: emphatically a database error, emphatically not a
        // version clash. setUp() rebuilds the schema for the next test.
        $pdo = $this->getPdo();
        $pdo->exec('DROP TABLE events');

        return new MySqlEventStorage($pdo);
    }

    protected function getStorageWithFactory(): EventStorageInterface
    {
        return new MySqlEventStorage(
            $this->getPdo(),
            null,
            new ReflectionEventFactory(),
        );
    }

    public function test_storeEventsSplitsIntoMultipleBatchesWhenConfiguredBatchSizeIsSmaller(): void
    {
        $storage = new MySqlEventStorage($this->getPdo(), null, null, 2);
        $aggregateId = EntityIdentifier::fromString('batch-size-agg');

        $events = [];
        for ($i = 1; $i <= 5; $i++) {
            $events[] = new AnotherDummyEvent($aggregateId, $i);
        }

        $storage->storeEvents($events);

        $this->assertCount(5, $storage->retrieveEvents($aggregateId), 'All events across multiple batches should be persisted.');
    }

    public function test_storeEventsWithAnEmptyBatchNeverStartsATransaction(): void
    {
        $pdo = $this->newPdo(TransactionCountingPdo::class);
        $storage = new MySqlEventStorage($pdo);

        $storage->storeEvents([]);

        $this->assertSame(
            0,
            $pdo->beginTransactionCalls,
            'An empty batch must be a no-op: opening a transaction to write nothing takes the metadata lock for no reason.'
        );
        $this->assertFalse($pdo->inTransaction(), 'No transaction may be left open.');
        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
            'An empty batch must not write anything.'
        );
    }

    public function test_storeEventsRollsBackAndRethrowsWhenAFailureIsNotADatabaseError(): void
    {
        $pdo = $this->getPdo();
        // Fails while building the second entry, so the first insert has already
        // executed inside the transaction when the batch blows up.
        $storage = new MySqlEventStorage($pdo, new FailingEventEntryFactory(2));
        $aggregateId = EntityIdentifier::fromString('non-db-failure-agg');

        $events = [
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ];

        try {
            $storage->storeEvents($events);
            $this->fail('The factory failure should have propagated out of storeEvents().');
        } catch (RuntimeException $exception) {
            $this->assertSame('Entry factory exploded.', $exception->getMessage(), 'A non-database failure must surface unchanged, not translated.');
        }

        $this->assertFalse($pdo->inTransaction(), 'The transaction must be rolled back, not left open.');
        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
            'The event written before the failure must be rolled back with the rest of the batch.'
        );
    }

    public function test_storeEventsDoesNotReportAnInfrastructureFailureAsAConcurrencyConflict(): void
    {
        $pdo = $this->getPdo();
        $storage = new MySqlEventStorage($pdo);
        $aggregateId = EntityIdentifier::fromString('infrastructure-failure-agg');

        // A missing table is a database error that is emphatically not a version
        // clash. Translating it to ConcurrencyException would send a consumer
        // that retries on that exception into an endless loop.
        $pdo->exec('DROP TABLE events');

        try {
            $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
            $this->fail('Writing to a missing table should have failed.');
        } catch (ConcurrencyException) {
            $this->fail('An infrastructure failure must keep its own identity, not arrive as a concurrency conflict.');
        } catch (PDOException $exception) {
            $this->assertNotSame('23000', $exception->getCode(), 'Guard: this case is meant to exercise a non-duplicate-key error.');
        }

        $this->assertFalse($pdo->inTransaction(), 'The transaction must be rolled back, not left open.');

        $this->setupEventsTable();
    }
    public function test_aStoredBatchQueuesOneDeliveryPerEvent(): void
    {
        $pdo = $this->getPdo();
        $outbox = new MySqlOutboxStorage($pdo);
        $storage = new MySqlEventStorage($pdo, null, null, 1000, $outbox);
        $aggregateId = EntityIdentifier::fromString('outbox-happy-path');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $this->assertSame(2, $outbox->countPending());
    }

    /**
     * The reason the outbox is a collaborator of the storage rather than
     * something composed around it: the entry is written inside the
     * transaction the events are written in. A batch that is rejected must
     * leave no pending delivery, or the relay ships an event the store never
     * kept.
     */
    public function test_aRejectedBatchLeavesNoPendingDeliveryBehind(): void
    {
        $pdo = $this->getPdo();
        $outbox = new MySqlOutboxStorage($pdo);
        $storage = new MySqlEventStorage($pdo, null, null, 1000, $outbox);
        $aggregateId = EntityIdentifier::fromString('outbox-atomicity');

        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
        $this->assertSame(1, $outbox->countPending(), 'Precondition: one event stored, one delivery queued.');

        try {
            $storage->storeEvents([
                new AnotherDummyEvent($aggregateId, 2),
                new AnotherDummyEvent($aggregateId, 1),
            ]);
            $this->fail('A batch reusing version 1 must be rejected.');
        } catch (ConcurrencyException) {
            // expected
        }

        $this->assertSame(1, $outbox->countPending(), 'The rejected batch must not have queued anything.');
        $this->assertCount(1, $storage->retrieveEvents($aggregateId), 'And must not have stored anything either.');
    }

    public function test_storageWithoutAnOutboxQueuesNothing(): void
    {
        $pdo = $this->getPdo();
        $outbox = new MySqlOutboxStorage($pdo);
        $storage = new MySqlEventStorage($pdo);

        $storage->storeEvents([new AnotherDummyEvent(EntityIdentifier::fromString('outbox-absent'), 1)]);

        $this->assertSame(0, $outbox->countPending(), 'The outbox is opt-in.');
    }

    /**
     * The property that makes an outbox worth having, asserted directly: the
     * pending delivery is written inside the caller's unit of work, so rolling
     * that back takes the entry with it. An outbox on its own connection would
     * survive the rollback and the relay would ship an event the store never
     * kept.
     */
    public function test_theOutboxEntryRollsBackWithTheEventsItDescribes(): void
    {
        $pdo = $this->getPdo();
        $outbox = new MySqlOutboxStorage($pdo);
        $storage = new MySqlEventStorage($pdo, null, null, 1000, $outbox);
        $aggregateId = EntityIdentifier::fromString('outbox-rollback');

        $pdo->beginTransaction();
        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
        $pdo->rollBack();

        $this->assertSame(0, $outbox->countPending(), 'The entry has to roll back with the events.');
        $this->assertCount(0, $storage->retrieveEvents($aggregateId));
    }

    /**
     * The coupling proved from the other direction, and the direction that
     * matters more: if the delivery cannot be recorded, the events must not be
     * stored either. Events with no pending delivery are exactly the silent
     * message loss the outbox exists to prevent, so the write has to fail as a
     * whole.
     */
    public function test_anOutboxThatCannotBeWrittenRollsBackTheEventsToo(): void
    {
        $pdo = $this->getPdo();
        $storage = new MySqlEventStorage($pdo, null, null, 1000, new MySqlOutboxStorage($pdo));
        $aggregateId = EntityIdentifier::fromString('outbox-unwritable');

        $pdo->exec('DROP TABLE outbox');

        try {
            $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
            $this->fail('Recording the delivery failed, so the write had to fail.');
        } catch (PDOException) {
            // expected
        }

        $this->assertCount(
            0,
            $storage->retrieveEvents($aggregateId),
            'The events must not survive a delivery that could not be recorded.'
        );

        $this->setupOutboxTable();
    }

    /**
     * The one place in this adapter that assembles SQL from something other
     * than a literal. The column names come from a consumer's own
     * getDatabaseFields(), so they are application code rather than user
     * input — but "unlikely to be hostile" is not "checked", and a field name
     * that cannot be a column is a mistake worth naming rather than a query
     * worth running.
     */
    public function test_aFieldNameThatCannotBeAColumnIsRefused(): void
    {
        $storage = new MySqlEventStorage($this->getPdo(), new HostileFieldEventEntryFactory());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid identifier');

        $storage->storeEvents([new AnotherDummyEvent(EntityIdentifier::fromString('hostile-field'), 1)]);
    }

    /**
     * The same storage, built so its writes are enqueued for a relay.
     *
     * @return EventStorageInterface|null
     */
    protected function getStorageDeliveringThroughOutbox(): ?EventStorageInterface
    {
        return new MySqlEventStorage($this->getPdo(), outbox: new MySqlOutboxStorage($this->getPdo()));
    }
}
