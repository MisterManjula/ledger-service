<?php

declare(strict_types=1);

namespace Ledger;

/**
 * What happened, kept separate from what is returned.
 *
 * §4 requires a replay to return the original result — which means the body of a
 * 200 and the body of a 201 must be indistinguishable. A "replayed": true field
 * inside the transfer would be the obvious way to report this and is exactly the
 * wrong one, so the flag travels beside the payload rather than inside it.
 */
final class TransferResult
{
    /**
     * @param array{id: string, from: string, to: string, amount: int, currency: string, created_at: string} $transfer
     */
    public function __construct(
        public readonly array $transfer,
        public readonly bool $replayed,
    ) {
    }
}
