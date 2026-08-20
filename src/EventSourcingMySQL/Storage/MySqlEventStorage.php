<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Storage;

use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxBackedStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use DomainFlow\EventSourcingMySQL\Support\ReadsDatabaseRows;
use InvalidArgumentException;
use JsonException;

use PDO;
use PDOException;
use Random\RandomException;
use ReflectionException;
use Throwable;

class MySqlEventStorage implements EventStorageInterface, OutboxBackedStorageInterface
{
    use ReadsDatabaseRows;

    /** MySQL's ER_DUP_ENTRY. */
    private const int DUPLICATE_KEY_ERROR = 1062;

    private PDO $pdo;
    private EventEntryFactoryInterface $entryFactory;
    private int $batchSize;
    private ?OutboxStorageInterface $outbox;

    public function __construct(
        PDO $pdo,
        ?EventEntryFactoryInterface $entryFactory = null,
        ?EventFactoryInterface $eventFactory = null,
        int $batchSize = 1000,
        ?OutboxStorageInterface $outbox = null
    ) {
        $this->pdo = $pdo;
        // The event factory belongs to the entry factory that uses it. Keeping
        // it as an instance dependency prevents one storage instance from
        // changing the serialization behavior of another instance.
        $this->entryFactory = $entryFactory ?? new DefaultEventEntryFactory($eventFactory);
        $this->batchSize = max(1, $batchSize);
        $this->outbox = $outbox;
    }

    /**
     * Appends a batch of events atomically.
     *
     * The whole call runs in one transaction, so a rejected batch leaves the
     * store exactly as it was — a half-written stream is unrecoverable, because
     * the aggregate believes it emitted events that can never be replayed.
     *
     * If the consumer already has a transaction open (an application-level unit
     * of work), this joins it rather than throwing, and leaves the commit or
     * rollback decision to the owner of that transaction.
     *
     * @param DomainEventInterface[] $events
     * @throws ConcurrencyException|JsonException|RandomException
     */
    public function storeEvents(
        array $events
    ): void {
        if ($events === []) {
            return;
        }

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach (array_chunk($events, max(1, $this->batchSize)) as $batch) {
                foreach ($batch as $event) {
                    $this->insertEvent($event);
                }
            }

            // Inside the transaction, so the pending delivery and the events
            // it describes commit together or not at all. A process dying
            // here leaves a pending entry rather than a lost message.
            $this->outbox?->enqueue($events);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (PDOException $exception) {
            $this->rollBackIfOwned($ownsTransaction);

            throw $this->translateWriteFailure($exception, $events[array_key_first($events)]);
        } catch (Throwable $throwable) {
            $this->rollBackIfOwned($ownsTransaction);

            throw $throwable;
        }
    }

    /**
     * @throws JsonException|RandomException
     */
    private function insertEvent(
        DomainEventInterface $event
    ): void {
        $values = $this->entryFactory->createFromDomainEvent($event)->getValues();

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        // The only place in this adapter that assembles SQL from something
        // other than a literal. The values are parameterised; the column names
        // come from the consumer's own getDatabaseFields(), so they are
        // application code rather than user input — but "unlikely to be
        // hostile" is not the same as "checked", and this is the one spot where
        // it costs nothing to check.
        $sql = sprintf(
            'INSERT INTO events (%s) VALUES (%s)',
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', $placeholders)
        );

        $this->pdo->prepare($sql)->execute($values);
    }

    /**
     * Backtick-quotes a column name, and refuses one that could not be a
     * column name to begin with.
     *
     * Rejecting rather than escaping: a backtick inside an identifier has no
     * legitimate use here, so quietly accepting it would only hide a mistake
     * in the consumer's field definitions.
     *
     * @param string $identifier
     * @return string
     */
    private function quoteIdentifier(
        string $identifier
    ): string {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Column name "%s" is not a valid identifier. Event field names come from getDatabaseFields(); '
                . 'this one cannot be used as a column.',
                $identifier
            ));
        }

        return '`' . $identifier . '`';
    }

    private function rollBackIfOwned(
        bool $ownsTransaction
    ): void {
        if ($ownsTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * A duplicate-key violation on uq_aggregate_version is a concurrency
     * conflict and belongs to Core's vocabulary. Everything else is an
     * infrastructure failure and must keep its own identity — reporting a
     * 16MB-payload or connection error as a concurrency conflict sends a
     * consumer that retries on ConcurrencyException into an endless loop.
     */
    private function translateWriteFailure(
        PDOException $exception,
        DomainEventInterface $event
    ): Throwable {
        $driverCode = $exception->errorInfo[1] ?? null;

        if ($exception->getCode() === '23000' && $driverCode === self::DUPLICATE_KEY_ERROR) {
            return new ConcurrencyException(
                sprintf(
                    'Event version %d for aggregate %s already exists.',
                    $event->getVersion()->toInt(),
                    (string) $event->getAggregateId()
                ),
                0,
                $exception
            );
        }

        return $exception;
    }

    /**
     * Retrieve events for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @throws ReflectionException
     * @return DomainEventInterface[]
     */
    public function retrieveEvents(
        EntityIdentifierInterface $aggregateId
    ): array {
        // Ordered by version, not occurred_on: a stream's order is defined by
        // the aggregate, not by the writing process's wall clock. This also
        // lets the uq_aggregate_version index serve the sort.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events WHERE aggregate_id = :aggregate_id ORDER BY version ASC'
        );
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);

        return $this->hydrateEvents($this->toRows($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Retrieve an aggregate's events newer than a given version.
     *
     * The bound is in the WHERE clause, not in a filter afterwards: the
     * snapshot load path exists to avoid reading the events a snapshot already
     * accounts for, and fetching them only to drop them in PHP would defeat it.
     * The uq_aggregate_version index serves both the range and the sort.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param EventVersion $afterVersion
     * @throws ReflectionException
     * @return DomainEventInterface[]
     */
    public function retrieveEventsFromVersion(
        EntityIdentifierInterface $aggregateId,
        EventVersion $afterVersion
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events WHERE aggregate_id = :aggregate_id AND version > :after_version ORDER BY version ASC'
        );
        $stmt->execute([
            'aggregate_id' => (string) $aggregateId,
            'after_version' => $afterVersion->toInt(),
        ]);

        return $this->hydrateEvents($this->toRows($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Retrieve every event in the store, in global order, lazily.
     *
     * @throws ReflectionException
     * @return iterable<DomainEventInterface>
     */
    public function retrieveAllEvents(): iterable
    {
        // Ordered by id, not occurred_on: database insertion order provides a
        // stable global position even when separate writers have skewed clocks.
        //
        // Rows are hydrated one at a time rather than materialized into an
        // array. PDO may still buffer the result set, but the more expensive
        // reflected event objects do not all exist at once.
        $statement = $this->pdo->query('SELECT * FROM events ORDER BY id ASC', PDO::FETCH_ASSOC);
        $rows = $statement === false ? [] : $statement;

        foreach ($rows as $row) {
            yield $this->entryFactory->recordToDomainEvent(
                EventPersistenceRecord::fromArray($this->toRow($row))
            );
        }
    }

    /**
     * Read the global stream from a position.
     *
     * The position is `events.id`, which MySQL only ever hands out in
     * increasing order. An event inserted between two reads therefore lands
     * after everything already returned, which is what lets a reader resume
     * from its last position without skipping or repeating anything — the
     * promise offset pagination cannot make.
     *
     * @param string|null $afterPosition
     * @param int $limit
     * @throws ReflectionException
     * @return GlobalEventPage
     */
    public function retrieveEventsFromPosition(
        ?string $afterPosition,
        int $limit
    ): GlobalEventPage {
        // The limit is interpolated as an integer rather than bound: a bound
        // parameter in LIMIT is emitted as a quoted string under PDO's emulated
        // prepares, which MySQL rejects.
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM events WHERE id > :after_position ORDER BY id ASC LIMIT %d',
            max(0, $limit)
        ));

        // Bound as a string, because BIGINT UNSIGNED reaches past PHP's signed
        // integer range and the comparison is MySQL's to make, not PHP's.
        $stmt->execute(['after_position' => $afterPosition ?? '0']);

        $rows = $this->toRows($stmt->fetchAll(PDO::FETCH_ASSOC));

        $position = $afterPosition;
        if ($rows !== []) {
            $position = $this->stringColumn($rows[array_key_last($rows)], 'id', $afterPosition ?? '');
        }

        return new GlobalEventPage($this->hydrateEvents($rows), $position);
    }

    /**
     * Retrieve paginated events.
     *
     * @param int|null $offset
     * @param int|null $limit
     * @throws ReflectionException
     * @return DomainEventInterface[]
     */
    public function retrievePaginatedEvents(
        ?int $offset = 0,
        ?int $limit = 100
    ): array {
        // The interface permits null for both bounds, and binding null as an
        // integer produced `LIMIT NULL` — a syntax error. MongoDB and Redis
        // honoured null all along, so this was three adapters giving three
        // answers at one interface. MySQL has no "no limit" literal, hence the
        // documented maximum.
        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM events ORDER BY id ASC LIMIT %d OFFSET %d',
            $limit ?? PHP_INT_MAX,
            max(0, $offset ?? 0)
        ));
        $statement->execute();

        return $this->hydrateEvents($this->toRows($statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Delete all events for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteEvents(
        EntityIdentifierInterface $aggregateId
    ): void {
        $stmt = $this->pdo->prepare('DELETE FROM events WHERE aggregate_id = :aggregate_id');
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);
    }

    /**
     * Hydrate events from rows.
     *
     * @param array<array<string, mixed>> $rows
     * @throws ReflectionException
     * @return DomainEventInterface[]
     */
    private function hydrateEvents(
        array $rows
    ): array {
        $events = [];

        foreach ($rows as $row) {
            $record = EventPersistenceRecord::fromArray($row);
            $events[] = $this->entryFactory->recordToDomainEvent($record);
        }

        return $events;
    }

    /**
     * Retrieve the current max version for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return EventVersion
     */
    public function getCurrentMaxVersion(
        EntityIdentifierInterface $aggregateId
    ): EventVersion {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(version) AS max_version FROM events WHERE aggregate_id = :aggregate_id'
        );
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $maxVersion = is_array($result) && isset($result['max_version']) && is_numeric($result['max_version'])
            ? (int) $result['max_version']
            : 0;

        return EventVersion::fromInt($maxVersion);
    }

    /**
     * Whether a relay, rather than this process, will deliver what is written
     * here.
     *
     * Read from the outbox handed to this storage: it is the
     * configuration in force, not the classes installed. `EventSourcingFacade`
     * needs the answer because the second delivery path — a dispatcher — is
     * given to *it*, and with both in place every event goes out twice with
     * nothing reporting it.
     *
     * @return bool
     */
    public function deliversThroughOutbox(): bool
    {
        return $this->outbox !== null;
    }
}
