<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySql\Tests\Unit\Storage;

use PDO;

/**
 * A PDO that remembers how often a transaction was started.
 *
 * `inTransaction()` after the fact cannot tell "never opened one" from
 * "opened one and closed it again", and that is exactly the distinction the
 * empty-batch guard exists for.
 */
final class TransactionCountingPdo extends PDO
{
    public int $beginTransactionCalls = 0;

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        return parent::beginTransaction();
    }
}
