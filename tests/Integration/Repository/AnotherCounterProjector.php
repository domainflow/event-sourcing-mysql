<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration\Repository;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcingCore\Provider\Integration\ProjectorDummyEvent;
use PDO;

final class AnotherCounterProjector implements ProjectorInterface
{
    private PDO $pdo;

    public function __construct(
        PDO $pdo
    ) {
        $this->pdo = $pdo;
    }

    public static function getSubscribedTo(): array
    {
        return [ProjectorDummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        if (!$this->supports($event::class)) {
            return;
        }
        /** @var ProjectorDummyEvent $event */
        $aggregateId = (string) $event->getAggregateId();
        $delta = $event->getDelta();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("SELECT counter FROM counter_projection WHERE aggregate_id = :agg");
        $stmt->execute(['agg' => $aggregateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $newCounter = (int) $row['counter'] + $delta;
            $updateStmt = $this->pdo->prepare("UPDATE counter_projection SET counter = :counter, updated_at = :updated_at WHERE aggregate_id = :agg");
            $updateStmt->execute([
                'counter' => $newCounter,
                'updated_at' => $now,
                'agg' => $aggregateId,
            ]);
        } else {
            $insertStmt = $this->pdo->prepare("INSERT INTO counter_projection (aggregate_id, counter, updated_at) VALUES (:agg, :counter, :updated_at)");
            $insertStmt->execute([
                'agg' => $aggregateId,
                'counter' => $delta,
                'updated_at' => $now,
            ]);
        }
    }

    public function reset(): void
    {
        $this->pdo->exec("DELETE FROM counter_projection;");
    }

    public function replay(
        DomainEventInterface ...$events
    ): void {
        foreach ($events as $event) {
            if ($this->supports($event::class)) {
                $this->handle($event);
            }
        }
    }

    public function supports(
        string $eventClass
    ): bool {
        return in_array($eventClass, self::getSubscribedTo(), true);
    }

    public function getName(): string
    {
        return 'AnotherCounterProjector';
    }
}
