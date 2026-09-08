<?php

declare(strict_types=1);

namespace Ledger;

use RuntimeException;

/**
 * A well-formed transfer the ledger declined. The transaction rolled back, so
 * no entry and no balance change survives.
 *
 * The reason names why. Mapping it to a status is the front controller's job:
 * the reason a transfer failed is a fact about the ledger, while the number
 * used to report it is a fact about HTTP.
 *
 * Named $reason and not $code because Exception already has a $code, and a
 * promoted readonly property cannot redeclare an inherited one.
 */
final class TransferRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
