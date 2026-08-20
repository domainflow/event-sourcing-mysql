<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Schema;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\SchemaManagerInterface;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractSchemaManagerTestCase;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use DomainFlow\EventSourcingMySQL\Schema\MySqlSchemaManager;
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;
use DomainFlow\EventSourcingMySql\Tests\Setup\DBHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[CoversClass(MySqlSchemaManager::class)]
#[UsesClass(MySqlEventStorage::class)]
final class MySqlSchemaManagerTest extends AbstractSchemaManagerTestCase
{
    use DBHelper;

    protected function tearDown(): void
    {
        // Every other test in this suite expects the tables to be there.
        $this->getSchemaManager()->ensureSchema();
    }

    protected function getSchemaManager(): SchemaManagerInterface
    {
        return new MySqlSchemaManager($this->getPdo());
    }

    protected function writeAnEvent(): void
    {
        $storage = new MySqlEventStorage($this->getPdo(), null, new ReflectionEventFactory());
        $storage->storeEvents([new AnotherDummyEvent(EntityIdentifier::fromString('schema-probe'), 1)]);
    }

    protected function schemaExists(): bool
    {
        $statement = $this->getPdo()->query("SHOW TABLES LIKE 'events'");

        return $statement !== false && $statement->fetchColumn() !== false;
    }

    /**
     * The files are the source of truth rather than a copy of them, so a path
     * that does not resolve has to say so — silently creating nothing would
     * leave a consumer with a store that looks set up until the first write.
     */
    public function test_a_migration_that_cannot_be_read_is_reported(): void
    {
        $manager = new MySqlSchemaManager($this->getPdo(), '/nonexistent/migrations');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read migration');

        @$manager->ensureSchema();
    }

    /**
     * The runner and the shipped files cannot drift, because there is only one
     * copy: the helper the rest of this suite builds tables with reads the same
     * six files this manager runs.
     */
    public function test_it_creates_every_table_the_package_ships_a_migration_for(): void
    {
        $manager = $this->getSchemaManager();
        $manager->dropSchema();
        $manager->ensureSchema();

        foreach (['events', 'snapshots', 'snapshot_history', 'process_manager_states', 'outbox', 'outbox_dead'] as $table) {
            $statement = $this->getPdo()->query(sprintf("SHOW TABLES LIKE '%s'", $table));

            $this->assertNotFalse($statement);
            $this->assertNotFalse($statement->fetchColumn(), sprintf('Table "%s" was not created.', $table));
        }
    }

    /**
     * The manager carries its own ordered list of the shipped files, because
     * the order is part of what it does and a directory listing has none. That
     * list is a second place to forget a new migration, so the count is
     * checked against what is actually shipped — the failure mode being a file
     * added to `migrations/` that nothing ever runs.
     */
    public function test_the_manager_knows_about_every_shipped_migration(): void
    {
        $shipped = glob(dirname(__DIR__, 3) . '/migrations/*.sql');

        $this->assertNotFalse($shipped);
        $this->assertCount(
            count($shipped),
            $this->getSchemaManager()->describeSchema(),
            'A migration is shipped that ensureSchema() never runs.'
        );
    }

    public function test_describe_schema_names_every_table_it_would_create(): void
    {
        $description = $this->getSchemaManager()->describeSchema();

        $this->assertCount(6, $description);
        $this->assertStringContainsString('events', $description[0]);
    }
}
