<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Snapshot;

use DateMalformedStringException;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use JsonException;
use PDO;
use RuntimeException;

final readonly class MySqlSnapshotHistoryStorage implements SnapshotHistoryStorageInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function persistVersioned(
        SnapshotInterface $snapshot
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO snapshot_history (aggregate_id, version, occurred_on, state)
             VALUES (:aggregate_id, :version, :occurred_on, :state)'
        );

        $stmt->execute([
            'aggregate_id' => (string) $snapshot->getAggregateId(),
            'version' => $snapshot->getVersion()->toInt(),
            'occurred_on' => $snapshot->getOccurredOn()->format('Y-m-d H:i:s.u'),
            'state' => json_encode($snapshot->getState(), JSON_THROW_ON_ERROR),
        ]);
    }

    public function deleteSingle(
        EntityIdentifierInterface $aggregateId,
        int $version
    ): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM snapshot_history WHERE aggregate_id = :aggregate_id AND version = :version'
        );

        $stmt->execute([
            'aggregate_id' => (string) $aggregateId,
            'version' => $version,
        ]);
    }

    public function deleteAll(
        EntityIdentifierInterface $aggregateId
    ): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM snapshot_history WHERE aggregate_id = :aggregate_id'
        );

        $stmt->execute(['aggregate_id' => (string) $aggregateId]);
    }

    /**
     * @throws DateMalformedStringException
     */
    public function retrieveAll(
        EntityIdentifierInterface $aggregateId
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM snapshot_history WHERE aggregate_id = :aggregate_id ORDER BY version ASC'
        );
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);

        /** @var array<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): GenericSnapshot {
            $rawJson = $row['state'] ?? '{}';
            $stateJson = is_string($rawJson) ? $rawJson : '{}';

            try {
                /** @var array<string, mixed> $state */
                $state = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $aggId = is_string($row['aggregate_id'] ?? null) ? $row['aggregate_id'] : 'unknown';
                $version = is_numeric($row['version'] ?? null) ? (string) $row['version'] : '?';

                throw new RuntimeException(
                    sprintf(
                        'Failed to decode snapshot history state for aggregate "%s" at version %s',
                        $aggId,
                        $version
                    ),
                    0,
                    $e
                );
            }

            $occurredOn = OccurredOn::fromString(is_string($row['occurred_on'] ?? null) ? $row['occurred_on'] : 'now');

            return new GenericSnapshot(
                EntityIdentifier::fromString(is_string($row['aggregate_id'] ?? null) ? $row['aggregate_id'] : ''),
                EventVersion::fromInt(is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0),
                $state,
                $occurredOn
            );
        }, $rows);
    }

}
