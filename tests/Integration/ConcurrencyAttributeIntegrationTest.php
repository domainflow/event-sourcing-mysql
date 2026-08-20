<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\ConcurrencyAttributeIntegrationTestCase;
use DomainFlow\EventSourcingMySql\Tests\Setup\DatabaseSetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ConcurrencyAttributeIntegrationTest extends ConcurrencyAttributeIntegrationTestCase
{
    use DatabaseSetup;
}
