<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\AggregateLifecycleIntegrationTestCase;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class AggregateLifecycleIntegrationTest extends AggregateLifecycleIntegrationTestCase
{
    use DatabaseSetup;
}
