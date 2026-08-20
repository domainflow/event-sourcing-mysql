<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcingCore\Provider\Integration\EventMigrationAndSerializationIntegrationTestCase;
use DomainFlow\EventSourcingCore\Provider\Integration\MigratableDummyEvent;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use DomainFlow\EventSourcingMySql\Tests\Setup\DBHelper;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EventMigrationAndSerializationIntegrationTest extends EventMigrationAndSerializationIntegrationTestCase
{
    use DatabaseSetup;
    use DBHelper;

    /**
     * @param array<string, mixed> $payload
     */
    protected function insertEvent(
        string $eventId,
        EntityIdentifier $aggregateId,
        string $eventClass,
        int $version,
        string $occurredOn,
        array $payload
    ): void {
        $stmt = $this->getPdo()->prepare("
        INSERT INTO events (event_id, aggregate_id, event_class, version, occurred_on, payload)
        VALUES (:event_id, :aggregate_id, :event_class, :version, :occurred_on, :payload)
    ");
        $stmt->execute([
            'event_id' => $eventId,
            'aggregate_id' => (string) $aggregateId,
            'event_class' => $eventClass,
            'version' => $version,
            'occurred_on' => $occurredOn,
            'payload' => json_encode($payload),
        ]);
    }

    protected function insertLegacyEvent(EntityIdentifier $aggregateId, string $eventId, string $occurredOn): void
    {
        $this->insertEvent(
            $eventId,
            $aggregateId,
            MigratableDummyEvent::class,
            1,
            $occurredOn,
            [
                'aggregateId' => (string) $aggregateId,
                'eventId' => $eventId,
                'occurredOn' => $occurredOn,
                'version' => 1,
                'delta' => 3,
            ]
        );
    }

}
