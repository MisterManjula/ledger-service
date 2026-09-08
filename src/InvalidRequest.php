<?php

declare(strict_types=1);

namespace Ledger;

use RuntimeException;

/**
 * The request could not be understood: it never reached the database, and
 * nothing was written. Becomes a 400 at the boundary.
 *
 * Distinct from TransferRejected, which means the request was understood
 * perfectly well and the ledger refused it.
 *
 * Named $reason and not $code because Exception already has a $code, and a
 * promoted readonly property cannot redeclare an inherited one.
 */
final class InvalidRequest extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
