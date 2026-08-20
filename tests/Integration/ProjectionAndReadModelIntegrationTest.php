<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\CounterProjectionRepositoryInterface;
use DomainFlow\EventSourcingCore\Provider\Integration\ProjectionAndReadModelIntegrationTestCase;
use DomainFlow\EventSourcingMySql\Tests\Integration\Repository\MySqlCounterProjectionRepository;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use DomainFlow\EventSourcingMySql\Tests\Setup\DBHelper;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ProjectionAndReadModelIntegrationTest extends ProjectionAndReadModelIntegrationTestCase
{
    use DBHelper;
    use DatabaseSetup;

    protected function setUpCounterProjections(): void
    {
        $this->getPdo()->exec("DROP TABLE IF EXISTS counter_projection;");
        $this->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS counter_projection (
                aggregate_id VARCHAR(255) PRIMARY KEY,
                counter INT NOT NULL,
                updated_at DATETIME NOT NULL
            );
        ");
    }

    protected function getCounterProjectionRepository(): CounterProjectionRepositoryInterface
    {
        return new MySqlCounterProjectionRepository($this->getPdo());
    }

    protected function getCounterFromProjection(string $aggregateId): ?int
    {
        $stmt = $this->getPdo()->prepare("SELECT counter FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['counter'] : null;
    }
    protected function getProjectionCounter(string $aggregateId): ?int
    {
        $stmt = $this->getPdo()->prepare("SELECT counter FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['counter'] : null;
    }

    protected function projectionRowExists(string $aggregateId): bool
    {
        $stmt = $this->getPdo()->prepare("SELECT 1 FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
