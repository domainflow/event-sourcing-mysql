<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Outbox;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractOutboxStorageTestCase;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use DomainFlow\EventSourcingMySQL\Outbox\MySqlOutboxStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MySqlOutboxStorage::class)]
final class MySqlOutboxStorageTest extends AbstractOutboxStorageTestCase
{
    use DatabaseSetup;

    protected function getOutbox(): OutboxStorageInterface
    {
        return new MySqlOutboxStorage($this->getPdo());
    }

    /**
     * This adapter takes no clock, so there is no skew to hand it:
     * the lease is `NOW(6)` inside the claiming UPDATE, which means every
     * relay is measured against the database's clock however many hosts they
     * run on. Two plain views is the honest answer, and it is the arrangement
     * the other two adapters were changed to match.
     *
     * @param int $leaseSeconds
     * @param int $skewSeconds
     * @return array{0: OutboxStorageInterface, 1: OutboxStorageInterface}
     */
    protected function getRelaysWithSkewedClocks(
        int $leaseSeconds,
        int $skewSeconds
    ): array {
        return [
            new MySqlOutboxStorage($this->getPdo(), null, $leaseSeconds),
            new MySqlOutboxStorage($this->getPdo(), null, $leaseSeconds),
        ];
    }

    /**
     * A relay that dies between claiming and marking would otherwise strand
     * its entries forever, and the failure mode is the worst kind: a queue
     * that stops draining while reporting nothing at all.
     */
    public function test_anExpiredClaimIsPickedUpByTheNextRelay(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxStranded'), 1)]);

        $this->assertCount(1, $outbox->reserve(1), 'Precondition: the first relay claims it.');
        $this->assertSame([], $outbox->reserve(1), 'And holds it while the lease is live.');

        // Age the claim rather than sleeping: the point is the lease boundary,
        // not how fast the test machine is.
        $this->getPdo()->exec('UPDATE outbox SET reserved_at = NOW(6) - INTERVAL 1 HOUR');

        $this->assertCount(
            1,
            $outbox->reserve(1),
            'With the lease lapsed the entry has to become claimable again.'
        );
    }

    /**
     * The move to `outbox_dead` is two statements, and if only the first of
     * them lands the entry exists in both tables at once: still claimable,
     * and already counted as a dead letter. The transaction is what rules
     * that out, so it needs a test that actually breaks the second half.
     */
    public function test_aFailedMoveToTheDeadLetterTableLeavesTheEntryPending(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxDeadMoveFails'), 1)]);

        $entry = $outbox->reserve(1)[0];

        // The destination is gone, so the INSERT ... SELECT fails and the
        // DELETE that follows it must not have happened.
        $this->getPdo()->exec('DROP TABLE outbox_dead');

        try {
            $outbox->markAbandoned($entry);
            $this->fail('A failed move must not be swallowed.');
        } catch (PDOException) {
            // The driver's error is the driver's to report.
        }

        $this->assertSame(1, $outbox->countPending(), 'The entry must still be there to try again.');
    }

    public function test_reservingNothingAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->getOutbox()->reserve(0));
    }
}
