<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\DummyAggregateIntegrationTestCase;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class DummyAggregateIntegrationTest extends DummyAggregateIntegrationTestCase
{
    use DatabaseSetup;
}
