<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Crypto;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Attribute\DataSubjectId;
use DomainFlow\EventSourcing\Attribute\PersonalData;
use DomainFlow\EventSourcing\Crypto\EncryptingEventEntryFactory;
use DomainFlow\EventSourcing\Crypto\InMemoryPersonalDataKeyStore;
use DomainFlow\EventSourcing\Crypto\RedactedValue;
use DomainFlow\EventSourcing\Crypto\SodiumCipher;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractEventStorageTestCase;
use DomainFlow\EventSourcingMySQL\Outbox\MySqlOutboxStorage;
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The whole storage contract, run through the crypto-shredding decorator.
 *
 * The storage takes its entry factory as an instance dependency, so the
 * encryption decorator integrates without adapter-specific behavior. This is
 * asserted here because the adapter stores the payload in a JSON column, where
 * assumptions about nested envelope shapes would otherwise be easy to miss.
 */
#[CoversClass(MySqlEventStorage::class)]
#[UsesClass(MySqlOutboxStorage::class)]
final class EncryptedMySqlEventStorageTest extends AbstractEventStorageTestCase
{
    use DatabaseSetup;

    private ?InMemoryPersonalDataKeyStore $keys = null;

    /**
     * Lazily rather than in setUp(): the suite's DatabaseSetup trait brings its
     * own setUp(), and a method here would hide it — the schema would never be
     * built, which is a confusing way to find out about method resolution.
     * PHPUnit builds a fresh test object per test, so this still resets.
     */
    private function keys(): InMemoryPersonalDataKeyStore
    {
        return $this->keys ??= new InMemoryPersonalDataKeyStore();
    }

    protected function getStorage(): EventStorageInterface
    {
        return new MySqlEventStorage($this->getPdo(), $this->encrypting(new DefaultEventEntryFactory(new ReflectionEventFactory())));
    }

    protected function getStorageWithFactory(): EventStorageInterface
    {
        return $this->getStorage();
    }

    protected function getStorageWhoseWritesFailWithoutConflict(): EventStorageInterface
    {
        $pdo = $this->getPdo();
        $pdo->exec('DROP TABLE events');

        return new MySqlEventStorage($pdo, $this->encrypting(new DefaultEventEntryFactory(new ReflectionEventFactory())));
    }

    private function encrypting(
        EventEntryFactoryInterface $inner
    ): EncryptingEventEntryFactory {
        return new EncryptingEventEntryFactory($inner, $this->keys(), new SodiumCipher());
    }

    public function test_an_erased_subject_is_redacted_when_the_stream_is_replayed(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('order-erased');

        $event = new EncryptedCustomerRegistered($aggregateId, null, 'customer-1', 'ada@example.com', 'ORD-42');
        $event->setVersion(EventVersion::fromInt(1));
        $storage->storeEvents([$event]);

        $stored = $this->getPdo()->query("SELECT payload FROM events WHERE aggregate_id = 'order-erased'");
        $this->assertNotFalse($stored);
        $payload = $stored->fetchColumn();
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('ada@example.com', $payload, 'The personal data reached the column in the clear.');

        $this->keys()->forget('customer-1');

        $replayed = $storage->retrieveEvents($aggregateId);

        $this->assertCount(1, $replayed);
        $this->assertInstanceOf(EncryptedCustomerRegistered::class, $replayed[0]);
        $this->assertTrue(RedactedValue::isRedacted($replayed[0]->email));
        $this->assertSame('ORD-42', $replayed[0]->orderReference);
    }

    /**
     * The same storage, built so its writes are enqueued for a relay.
     *
     * @return EventStorageInterface|null
     */
    protected function getStorageDeliveringThroughOutbox(): ?EventStorageInterface
    {
        return new MySqlEventStorage($this->getPdo(), outbox: new MySqlOutboxStorage($this->getPdo()));
    }
}

final class EncryptedCustomerRegistered extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public string $email = '',
        public string $orderReference = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'email' => $this->email,
            'orderReference' => $this->orderReference,
        ];
    }
}
