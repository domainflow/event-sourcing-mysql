<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\ProcessManager;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use DomainFlow\EventSourcingMySQL\Support\ReadsDatabaseRows;
use JsonException;

use PDO;
use RuntimeException;

final class MySqlProcessManagerStorage implements ProcessManagerStorageInterface
{
    use ReadsDatabaseRows;

    private PDO $pdo;

    public function __construct(
        PDO $pdo
    ) {
        $this->pdo = $pdo;
    }

    /**
     * Conditional on the version the state was loaded at.
     *
     * REPLACE INTO cannot express that — it deletes and reinserts regardless of
     * what was there — so a first write is an INSERT and every later one an
     * UPDATE with `WHERE version = :expected`. A row count of zero means
     * another worker got there first.
     *
     * @param ProcessManagerState $state
     * @throws JsonException|ProcessManagerConcurrencyException
     * @return void
     */
    public function store(
        ProcessManagerState $state
    ): void {
        $expected = $state->getVersion();
        $next = $expected + 1;

        $values = [
            'process_id' => (string) $state->getProcessId(),
            'status' => $state->getStatus()->value,
            'data' => json_encode($state->getData(), JSON_THROW_ON_ERROR),
            'timeout' => $state->getTimeout()?->format('Y-m-d H:i:s.u'),
            'version' => $next,
        ];

        $written = $expected === 0
            ? $this->insertNew($values)
            : $this->updateExisting($values, $expected);

        if ($written !== 1) {
            throw ProcessManagerConcurrencyException::versionMoved(
                $state->getProcessId(),
                $expected,
                $this->storedVersion($state->getProcessId())
            );
        }

        $state->markPersisted($next);
    }

    /**
     * @param array<string, mixed> $values
     * @return int
     */
    private function insertNew(
        array $values
    ): int {
        // A duplicate key here means a concurrent worker inserted the same
        // process first, which is the same conflict as a moved version.
        $statement = $this->pdo->prepare(
            'INSERT INTO process_manager_states (process_id, status, data, timeout, version) '
            . 'SELECT :process_id, :status, :data, :timeout, :version FROM DUAL '
            . 'WHERE NOT EXISTS (SELECT 1 FROM process_manager_states WHERE process_id = :existing_id)'
        );

        $statement->execute($values + ['existing_id' => $values['process_id']]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $values
     * @param int $expected
     * @return int
     */
    private function updateExisting(
        array $values,
        int $expected
    ): int {
        $statement = $this->pdo->prepare(
            'UPDATE process_manager_states SET status = :status, data = :data, timeout = :timeout, '
            . 'version = :version WHERE process_id = :process_id AND version = :expected'
        );

        $statement->execute($values + ['expected' => $expected]);

        return $statement->rowCount();
    }

    private function storedVersion(
        EntityIdentifierInterface $processId
    ): int {
        $statement = $this->pdo->prepare('SELECT version FROM process_manager_states WHERE process_id = :process_id');
        $statement->execute(['process_id' => (string) $processId]);

        $version = $statement->fetchColumn();

        return is_numeric($version) ? (int) $version : 0;
    }

    public function retrieve(
        EntityIdentifierInterface $processId
    ): ?ProcessManagerState {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM process_manager_states WHERE process_id = :process_id'
        );
        $stmt->execute(['process_id' => (string) $processId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !is_array($row)) {
            return null;
        }

        return $this->hydrate($this->toRow($row), (string) $processId);
    }

    /**
     * Overdue processes, oldest first, still running.
     *
     * `status NOT IN` rather than a positive list, so a status added later is
     * treated as still running instead of silently dropping out of every
     * timeout worker's view.
     *
     * The ordering is the contract's, and the index on `timeout` is what keeps
     * it from being a filesort over the whole table every poll.
     *
     * @param DateTimeImmutable $asOf
     * @param int $limit
     * @return list<ProcessManagerState>
     */
    public function findTimedOut(
        DateTimeImmutable $asOf,
        int $limit
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT * FROM process_manager_states '
            . 'WHERE timeout IS NOT NULL AND timeout <= :as_of AND status NOT IN (:completed, :failed) '
            . 'ORDER BY timeout ASC LIMIT :page_size'
        );

        $statement->bindValue(':as_of', $asOf->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
        $statement->bindValue(':completed', ProcessManagerStateEnum::COMPLETED->value);
        $statement->bindValue(':failed', ProcessManagerStateEnum::FAILED->value);
        // LIMIT will not take a string, and PDO binds one unless told otherwise.
        $statement->bindValue(':page_size', max(0, $limit), PDO::PARAM_INT);
        $statement->execute();

        $states = [];

        foreach ($this->toRows($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $states[] = $this->hydrate($row, $this->stringColumn($row, 'process_id'));
        }

        return $states;
    }

    /**
     * A row as PDO hands it over: keys and values both untyped, which is why
     * every read below states what it expects rather than assuming it.
     *
     * @param array<array-key, mixed> $row
     * @param string $processId
     * @throws RuntimeException
     * @return ProcessManagerState
     */
    private function hydrate(
        array $row,
        string $processId
    ): ProcessManagerState {
        $rawJson = $row['data'] ?? '{}';
        $dataJson = is_string($rawJson) ? $rawJson : '{}';

        try {
            /** @var array<string, mixed> $decodedData */
            $decodedData = json_decode($dataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Failed to decode process manager data for process "%s": %s', $processId, $e->getMessage()),
                0,
                $e
            );
        }

        $state = new ProcessManagerState(
            EntityIdentifier::fromString(is_string($row['process_id'] ?? null) ? $row['process_id'] : $processId),
            ProcessManagerStateEnum::from(is_string($row['status'] ?? null) ? $row['status'] : ProcessManagerStateEnum::WAITING->value),
            is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0
        );
        $state->setData($decodedData);

        if (is_string($row['timeout'] ?? null)) {
            // Stated, not inferred. The column has no offset, everything
            // written here is UTC, and a runtime in another zone would
            // otherwise read the value as local time and move it.
            $state->setTimeout(new DateTimeImmutable($row['timeout'], new DateTimeZone('UTC')));
        }

        return $state;
    }

    public function delete(
        EntityIdentifierInterface $processId
    ): void {
        $stmt = $this->pdo->prepare('DELETE FROM process_manager_states WHERE process_id = :process_id');
        $stmt->execute(['process_id' => (string) $processId]);
    }
}
