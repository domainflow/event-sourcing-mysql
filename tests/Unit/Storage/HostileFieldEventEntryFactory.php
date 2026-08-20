<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Storage;

use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;

/**
 * Produces a record with a field name that is not a legal column.
 *
 * An event's getDatabaseFields() decides the column list, so this is the shape
 * a mistake in consumer code takes — and the shape an injection would take if
 * those names ever came from somewhere less trustworthy.
 */
final class HostileFieldEventEntryFactory implements EventEntryFactoryInterface
{
    private readonly DefaultEventEntryFactory $delegate;

    public function __construct()
    {
        $this->delegate = new DefaultEventEntryFactory();
    }

    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        $values = $this->delegate->createFromDomainEvent($event)->getValues();
        $values['payload`, (SELECT 1)) -- '] = 'x';

        return EventPersistenceRecord::fromArray($values);
    }

    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        return $this->delegate->recordToDomainEvent($record);
    }
}
