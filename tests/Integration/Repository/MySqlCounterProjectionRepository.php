<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration\Repository;

use DateTimeImmutable;
use DomainFlow\EventSourcingCore\Provider\Integration\CounterProjectionRepositoryInterface;
use PDO;

final class MySqlCounterProjectionRepository implements CounterProjectionRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function getCounter(
        string $aggregateId
    ): ?int {
        $stmt = $this->pdo->prepare("SELECT counter FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['counter'] : null;
    }

    public function saveCounter(
        string $aggregateId,
        int $counter
    ): void {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("SELECT 1 FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);

        if ($stmt->fetch()) {
            $stmt = $this->pdo->prepare("
                UPDATE counter_projection
                SET counter = :counter, updated_at = :updated_at
                WHERE aggregate_id = :agg
            ");
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO counter_projection (aggregate_id, counter, updated_at)
                VALUES (:agg, :counter, :updated_at)
            ");
        }

        $stmt->execute([
            'agg' => $aggregateId,
            'counter' => $counter,
            'updated_at' => $now,
        ]);
    }

    public function reset(): void
    {
        $this->pdo->exec("DELETE FROM counter_projection;");
    }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM counter_projection")->fetchAll(PDO::FETCH_ASSOC);
    }
}
