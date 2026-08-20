<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Snapshot;

use DateMalformedStringException;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use JsonException;
use PDO;
use RuntimeException;

final class MySqlSnapshotStorage implements SnapshotStorageInterface
{
    private PDO $pdo;

    public function __construct(
        PDO $pdo
    ) {
        $this->pdo = $pdo;
    }

    public function storeSnapshot(
        SnapshotInterface $snapshot
    ): void {
        $stmt = $this->pdo->prepare(
            'REPLACE INTO snapshots (aggregate_id, version, occurred_on, state, snapshot_class)
     VALUES (:aggregate_id, :version, :occurred_on, :state, :snapshot_class)'
        );

        $stmt->execute([
            'aggregate_id' => (string) $snapshot->getAggregateId(),
            'version' => $snapshot->getVersion()->toInt(),
            'occurred_on' => $snapshot->getOccurredOn()->format('Y-m-d H:i:s.u'),
            'state' => json_encode($snapshot->getState(), JSON_THROW_ON_ERROR),
            'snapshot_class' => get_class($snapshot),
        ]);

    }

    public function deleteSnapshot(
        EntityIdentifierInterface $aggregateId
    ): void {
        $stmt = $this->pdo->prepare('DELETE FROM snapshots WHERE aggregate_id = :aggregate_id');
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);
    }

    /**
     * @throws DateMalformedStringException
     */
    public function retrieveSnapshot(
        EntityIdentifierInterface $aggregateId
    ): ?SnapshotInterface {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM snapshots WHERE aggregate_id = :aggregate_id ORDER BY version DESC LIMIT 1'
        );
        $stmt->execute(['aggregate_id' => (string) $aggregateId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !is_array($row)) {
            return null;
        }

        $rawJson = $row['state'] ?? '{}';
        $stateJson = is_string($rawJson) ? $rawJson : '{}';

        try {
            /** @var array<string, mixed> $decodedState */
            $decodedState = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Failed to decode snapshot state for aggregate "%s": %s', $aggregateId, $e->getMessage()),
                0,
                $e
            );
        }

        $occurredOn = OccurredOn::fromString(is_string($row['occurred_on'] ?? null) ? $row['occurred_on'] : 'now');

        return new GenericSnapshot(
            EntityIdentifier::fromString(is_string($row['aggregate_id'] ?? null) ? $row['aggregate_id'] : ''),
            EventVersion::fromInt(is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0),
            $decodedState,
            $occurredOn
        );
    }

}
