<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\ProcessManager;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractProcessManagerStorageTestCase;
use DomainFlow\EventSourcingMySQL\ProcessManager\MySqlProcessManagerStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(MySqlProcessManagerStorage::class)]
final class MySqlProcessManagerStorageTest extends AbstractProcessManagerStorageTestCase
{
    use DatabaseSetup;

    protected function getProcessManagerStorage(): ProcessManagerStorageInterface
    {
        return new MySqlProcessManagerStorage($this->getPdo());
    }

    public function test_retrieveThrowsOnCorruptJsonData(): void
    {
        $pdo = $this->getPdo();

        // The production/default schema uses a native JSON column, which MySQL
        // itself validates on INSERT - a genuinely malformed value can never
        // reach the `data` column that way. Recreate the table with a plain
        // TEXT column here so this specific error-handling path (a corrupted
        // row from, say, a manual DB edit) can actually be exercised.
        $pdo->exec("DROP TABLE IF EXISTS process_manager_states");
        $pdo->exec("
            CREATE TABLE process_manager_states (
                process_id VARCHAR(64) NOT NULL PRIMARY KEY,
                status VARCHAR(32) NOT NULL,
                data TEXT NOT NULL,
                timeout DATETIME(6) NULL
            )
        ");
        $pdo->exec("
            INSERT INTO process_manager_states (process_id, status, data, timeout)
            VALUES ('corrupt-process', 'processing', 'NOT_VALID_JSON', NULL)
        ");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode process manager data for process "corrupt-process"');

        $this->getProcessManagerStorage()->retrieve(EntityIdentifier::fromString('corrupt-process'));
    }

    public function test_retrieveFallsBackToWaitingWhenStatusIsMissing(): void
    {
        $pdo = $this->getPdo();

        // Same rationale as the corrupt-JSON test: relax the NOT NULL/type
        // constraints that the production schema enforces so the defensive
        // fallback for an unexpected row shape can actually be exercised.
        $pdo->exec("DROP TABLE IF EXISTS process_manager_states");
        $pdo->exec("
            CREATE TABLE process_manager_states (
                process_id VARCHAR(64) NOT NULL PRIMARY KEY,
                status VARCHAR(32) NULL,
                data JSON NOT NULL,
                timeout DATETIME(6) NULL
            )
        ");
        $pdo->exec("
            INSERT INTO process_manager_states (process_id, status, data, timeout)
            VALUES ('missing-status-process', NULL, '{}', NULL)
        ");

        $retrieved = $this->getProcessManagerStorage()->retrieve(EntityIdentifier::fromString('missing-status-process'));

        $this->assertNotNull($retrieved);
        $this->assertSame(ProcessManagerStateEnum::WAITING, $retrieved->getStatus());
    }

    public function test_storeAndRetrieveRoundtripsTimeout(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('timeout-process');
        $timeout = new DateTimeImmutable('2024-06-15 10:30:00.123456');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout($timeout);
        $storage->store($state);

        $retrieved = $storage->retrieve($processId);

        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->getTimeout());
        $this->assertSame($timeout->format('Y-m-d H:i:s.u'), $retrieved->getTimeout()->format('Y-m-d H:i:s.u'));
    }
}
