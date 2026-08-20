<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Outbox;

use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use DomainFlow\EventSourcing\Outbox\OutboxEntry;
use DomainFlow\EventSourcingMySQL\Support\ReadsDatabaseRows;
use DomainFlow\Uuid\UuidV6;

use PDO;
use ReflectionException;
use Throwable;

/**
 * The outbox table, on the same connection as the events.
 *
 * Sharing the PDO is not incidental: `enqueue()` is called from inside
 * `MySqlEventStorage::storeEvents()`, within the transaction that adapter
 * already owns, so the entry and the events commit together or not at all.
 * Handing this class a second connection would silently break that and look
 * like it worked.
 */
final class MySqlOutboxStorage implements OutboxStorageInterface
{
    use ReadsDatabaseRows;

    private readonly EventEntryFactoryInterface $entryFactory;

    /**
     * This adapter takes no clock because the lease is computed by the database
     * with `NOW(6)` inside the claiming UPDATE. Every relay is therefore
     * measured against the same clock, regardless of the host it runs on.
     *
     * The cost of the better arrangement is that the lease boundary cannot be
     * moved from a test, so it is asserted by ageing the row instead. That is
     * the trade, and this is the reason.
     *
     * @param PDO $pdo The same connection the event storage writes on.
     * @param int $leaseSeconds How long a claim is honoured before another
     *        relay may take the entry. Without this, a relay that dies between
     *        claiming and marking strands its entries forever — the failure
     *        mode is a queue that stops draining with no error anywhere.
     */
    public function __construct(
        private readonly PDO $pdo,
        ?EventEntryFactoryInterface $entryFactory = null,
        private readonly int $leaseSeconds = 300
    ) {
        $this->entryFactory = $entryFactory ?? new DefaultEventEntryFactory();
    }

    /**
     * @param array<DomainEventInterface> $events
     * @return void
     */
    public function enqueue(
        array $events
    ): void {
        if ($events === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO outbox (event_id, aggregate_id, event_class, version, occurred_on, payload, metadata) '
            . 'VALUES (:event_id, :aggregate_id, :event_class, :version, :occurred_on, :payload, :metadata)'
        );

        foreach ($events as $event) {
            $values = $this->entryFactory->createFromDomainEvent($event)->getValues();

            $statement->execute([
                'event_id' => $values['event_id'] ?? (string) UuidV6::generate(),
                'aggregate_id' => $values['aggregate_id'] ?? '',
                'event_class' => $values['event_class'] ?? '',
                'version' => $values['version'] ?? 0,
                'occurred_on' => $values['occurred_on'] ?? '',
                'payload' => $values['payload'] ?? '{}',
                // Carry metadata with the event so correlation and causation
                // information are preserved for downstream consumers.
                'metadata' => $values['metadata'] ?? '[]',
            ]);
        }
    }

    /**
     * Claims entries by stamping them, then reading back what was stamped.
     *
     * One UPDATE is atomic on its own, so two relays racing cannot end up with
     * the same row: whichever writes second sees no unclaimed rows left to
     * take. That is cheaper than SELECT ... FOR UPDATE SKIP LOCKED and needs
     * no transaction of its own, which matters because a relay is a plain
     * loop, not a unit of work.
     *
     * @param int $limit
     * @throws ReflectionException
     * @return list<OutboxEntry>
     */
    public function reserve(
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $token = (string) UuidV6::generate();

        $claim = $this->pdo->prepare(sprintf(
            'UPDATE outbox SET reserved_at = NOW(6), reserved_by = :token '
            . 'WHERE reserved_at IS NULL OR reserved_at < NOW(6) - INTERVAL %d SECOND '
            . 'ORDER BY id ASC LIMIT %d',
            max(0, $this->leaseSeconds),
            $limit
        ));
        $claim->execute(['token' => $token]);

        $read = $this->pdo->prepare('SELECT * FROM outbox WHERE reserved_by = :token ORDER BY id ASC');
        $read->execute(['token' => $token]);

        $entries = [];

        foreach ($this->toRows($read->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $entries[] = new OutboxEntry(
                $this->stringColumn($row, 'id'),
                $this->entryFactory->recordToDomainEvent(EventPersistenceRecord::fromArray($row)),
                $this->intColumn($row, 'attempts')
            );
        }

        return $entries;
    }

    public function markDelivered(
        OutboxEntry $entry
    ): void {
        $statement = $this->pdo->prepare('DELETE FROM outbox WHERE id = :id');
        $statement->execute(['id' => $entry->getId()]);
    }

    public function markFailed(
        OutboxEntry $entry
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE outbox SET attempts = attempts + 1, reserved_at = NULL, reserved_by = NULL WHERE id = :id'
        );
        $statement->execute(['id' => $entry->getId()]);
    }

    /**
     * Moves the row to `outbox_dead` and deletes it from the hot table.
     *
     * Two statements rather than a flag column, wrapped in a transaction where
     * the caller does not already hold one. A flag would mean an
     * extra predicate on `reserve()`'s claiming UPDATE, paid by every relay on
     * every pass forever; a separate table keeps the queue's hot path exactly
     * as it was and gives an operator something they can query at leisure
     * without touching it.
     *
     * The INSERT ... SELECT carries the row's own id across, so a log line
     * naming an outbox id still finds the entry after it has been abandoned.
     * `INSERT IGNORE` makes a repeated call harmless, which it has to be: a
     * relay dying between abandoning and recording it retries the whole step.
     *
     * @param OutboxEntry $entry
     * @return void
     */
    public function markAbandoned(
        OutboxEntry $entry
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $move = $this->pdo->prepare(
                'INSERT IGNORE INTO outbox_dead '
                . '(id, event_id, aggregate_id, event_class, version, occurred_on, payload, metadata, attempts, abandoned_at) '
                . 'SELECT id, event_id, aggregate_id, event_class, version, occurred_on, payload, metadata, attempts, NOW(6) '
                . 'FROM outbox WHERE id = :id'
            );
            $move->execute(['id' => $entry->getId()]);

            $remove = $this->pdo->prepare('DELETE FROM outbox WHERE id = :id');
            $remove->execute(['id' => $entry->getId()]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $throwable) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @param int $limit
     * @throws ReflectionException
     * @return list<OutboxEntry>
     */
    public function retrieveAbandoned(
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        // prepare(), like reserve() — a plain query() returns false rather
        // than a statement when the driver is in silent error mode, and the
        // guard for that would be a branch no test can reach.
        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM outbox_dead ORDER BY id ASC LIMIT %d',
            $limit
        ));
        $statement->execute();

        $entries = [];

        foreach ($this->toRows($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $entries[] = new OutboxEntry(
                $this->stringColumn($row, 'id'),
                $this->entryFactory->recordToDomainEvent(EventPersistenceRecord::fromArray($row)),
                $this->intColumn($row, 'attempts')
            );
        }

        return $entries;
    }

    public function countPending(): int
    {
        return $this->countRowsIn('outbox');
    }

    public function countAbandoned(): int
    {
        return $this->countRowsIn('outbox_dead');
    }

    /**
     * @param string $table Never consumer input — one of this class's own two
     *        table names, which is why it can be interpolated.
     * @return int
     */
    private function countRowsIn(
        string $table
    ): int {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
