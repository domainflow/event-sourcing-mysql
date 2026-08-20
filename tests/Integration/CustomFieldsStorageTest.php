<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcingCore\Provider\Integration\CustomEventEntryFactory;
use DomainFlow\EventSourcingCore\Provider\Integration\CustomFieldsStorageTestCase;
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class CustomFieldsStorageTest extends CustomFieldsStorageTestCase
{
    use DatabaseSetup;

    public function setUp(): void
    {
        parent::setUp();
        $factory = new CustomEventEntryFactory();
        $this->setupEventsTableFromFactory($factory);
    }

    public function getStorageWithFactory(
        EventEntryFactoryInterface $factory
    ): MySqlEventStorage {
        return new MySqlEventStorage(
            $this->getPdo(),
            $factory
        );
    }

    public function setupEventsTableFromFactory(
        EventEntryFactoryInterface $factory
    ): void {
        $pdo = $this->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS events");

        // Base/default columns required for EventPersistenceRecord
        $columns = [
            'event_id CHAR(36) PRIMARY KEY',
            'aggregate_id VARCHAR(255) NOT NULL',
            'event_class VARCHAR(255) NOT NULL',
            'version INT NOT NULL',
            'occurred_on DATETIME(6) NOT NULL',
            'payload JSON NOT NULL',
        ];

        // Append custom fields from factory if defined
        if (method_exists($factory, 'getFieldDefinitions')) {
            $customDefs = $factory->getFieldDefinitions();

            foreach ($customDefs as $name => $type) {

                $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
                $type = strtoupper($type);
                $columns[] = "$name $type";
            }
        }

        $sql = sprintf('CREATE TABLE events (%s)', implode(",\n", $columns));
        $pdo->exec($sql);
    }
}
