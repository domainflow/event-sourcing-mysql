<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\OrderSagaIntegrationTestCase;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class OrderSagaIntegrationTest extends OrderSagaIntegrationTestCase
{
    use DatabaseSetup;
}
