<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Storage;

use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use RuntimeException;

/**
 * Delegates to the real factory until the configured call, then throws
 * something that is not a PDOException.
 *
 * Used to drive MySqlEventStorage's generic failure path: a serializer,
 * upcaster or custom entry factory blowing up mid-batch is not a database
 * error, so it must not be translated. The transaction must still be rolled
 * back so a partially written event stream cannot remain.
 */
final class FailingEventEntryFactory implements EventEntryFactoryInterface
{
    private int $calls = 0;

    private readonly DefaultEventEntryFactory $delegate;

    public function __construct(
        private readonly int $failOnCall = 1
    ) {
        $this->delegate = new DefaultEventEntryFactory();
    }

    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        $this->calls++;

        if ($this->calls === $this->failOnCall) {
            throw new RuntimeException('Entry factory exploded.');
        }

        return $this->delegate->createFromDomainEvent($event);
    }

    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        return $this->delegate->recordToDomainEvent($record);
    }
}
