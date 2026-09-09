<?php

declare(strict_types=1);

namespace Ledger\Tests;

/**
 * Test 1 of §5.
 *
 * The assertion that matters is not that the response is a 422 — an application
 * that read the balance first and returned 422 itself would also pass that. It
 * is that nothing was written: no transfer row, no entries, no balance change.
 * Only a transaction that was rolled back by the database leaves that state.
 */
final class OverdraftTest extends LedgerTestCase
{
    public function testOverdraftIsRejectedAndWritesNothing(): void
    {
        $from = $this->createAccount(10_000);
        $to = $this->createAccount(0);
        $key = $this->idempotencyKey('overdraft');

        $response = $this->postTransfer([
            'from' => $from,
            'to' => $to,
            'amount' => 10_001,
            'currency' => 'EUR',
        ], $key);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'insufficient_funds'], $response['body']);

        self::assertSame(10_000, $this->balanceOf($from), 'Debited account changed');
        self::assertSame(0, $this->balanceOf($to), 'Credited account changed');

        // The transfers row is inserted before the balance update that violates
        // the constraint, so its absence is what proves the rollback undid the
        // whole transaction rather than just the failing statement.
        self::assertFalse($this->transferExists($key), 'A rejected transfer left a row behind');

        self::assertSame(1, $this->countEntries($from), 'Only the opening entry should exist');
        self::assertSame(0, $this->countEntries($to), 'An empty account should have no entries');
    }

    /**
     * The other side of the boundary.
     *
     * Without this, the suite would still pass if balance_non_negative were
     * written as `balance > 0`: every overdraft would be refused, and so would
     * every legitimate transfer that happens to empty an account. A test that can
     * only fail in one direction does not pin down the constraint it claims to.
     */
    public function testTransferOfTheEntireBalanceIsAccepted(): void
    {
        $from = $this->createAccount(10_000);
        $to = $this->createAccount(0);

        $response = $this->postTransfer([
            'from' => $from,
            'to' => $to,
            'amount' => 10_000,
            'currency' => 'EUR',
        ], $this->idempotencyKey('exact-balance'));

        self::assertSame(201, $response['status']);

        self::assertSame(0, $this->balanceOf($from));
        self::assertSame(10_000, $this->balanceOf($to));
    }
}
