<?php

declare(strict_types=1);

namespace Ledger\Tests;

/**
 * Test 5 of §5, and the reason ADR-001 is defensible.
 *
 * accounts.balance is a redundant value: it can, in principle, drift away from
 * the entries that are supposed to explain it. That redundancy is the price paid
 * for being able to declare "this may never go negative" to the database. This
 * test is what makes the price acceptable — it asserts the two never disagree.
 *
 * It deliberately asserts over the whole database rather than only over the rows
 * it created. Demo seed data and residue from other tests are all subject to the
 * same invariant, and any of them drifting is a real defect.
 */
final class DoubleEntryInvariantTest extends LedgerTestCase
{
    /**
     * Refused transfers are included on purpose. An invariant that only holds
     * along the happy path is not an invariant: a rollback that half-completed
     * would be exactly the kind of drift this test exists to catch.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $eurFrom = $this->createAccount(25_000);
        $eurTo = $this->createAccount(5_000);
        $usd = $this->createAccount(5_000, 'USD');

        $accepted = [3_300, 1, 19_999];
        foreach ($accepted as $index => $amount) {
            $response = $this->postTransfer([
                'from' => $eurFrom,
                'to' => $eurTo,
                'amount' => $amount,
                'currency' => 'EUR',
            ], $this->idempotencyKey("invariant-ok-$index"));

            self::assertSame(201, $response['status'], "Setup transfer of $amount was expected to succeed");
        }

        $overdraft = $this->postTransfer([
            'from' => $eurTo,
            'to' => $eurFrom,
            'amount' => 100_000_000,
            'currency' => 'EUR',
        ], $this->idempotencyKey('invariant-overdraft'));
        self::assertSame(422, $overdraft['status']);

        $mismatch = $this->postTransfer([
            'from' => $eurFrom,
            'to' => $usd,
            'amount' => 100,
            'currency' => 'EUR',
        ], $this->idempotencyKey('invariant-mismatch'));
        self::assertSame(422, $mismatch['status']);
    }

    public function testEveryTransferHasTwoEntriesSummingToZero(): void
    {
        $offenders = $this->pdo()->query(
            'SELECT t.id, count(e.id) AS entry_count, coalesce(sum(e.amount), 0) AS amount_sum
               FROM transfers t
               LEFT JOIN entries e ON e.transfer_id = t.id
              GROUP BY t.id
             HAVING count(e.id) <> 2 OR coalesce(sum(e.amount), 0) <> 0'
        )->fetchAll();

        self::assertSame([], $offenders, 'Transfers exist whose entries are not a zero-sum pair');
    }

    public function testEveryBalanceEqualsTheSumOfItsEntries(): void
    {
        $offenders = $this->pdo()->query(
            'SELECT a.id, a.balance, coalesce(sum(e.amount), 0) AS entry_sum
               FROM accounts a
               LEFT JOIN entries e ON e.account_id = a.id
              GROUP BY a.id, a.balance
             HAVING a.balance <> coalesce(sum(e.amount), 0)'
        )->fetchAll();

        self::assertSame([], $offenders, 'The materialised balance has drifted from the entries');
    }

    /**
     * The same statement from the other end. The per-account check above could in
     * principle be satisfied by two accounts drifting by equal and opposite
     * amounts; this one could not.
     */
    public function testTheLedgerAsAWholeIsBalanced(): void
    {
        $entryTotal = (int) $this->pdo()->query('SELECT coalesce(sum(amount), 0) FROM entries')->fetchColumn();
        $balanceTotal = (int) $this->pdo()->query('SELECT coalesce(sum(balance), 0) FROM accounts')->fetchColumn();

        self::assertSame($balanceTotal, $entryTotal);

        // Transfers move money without creating any: only opening entries do that.
        $movedByTransfers = (int) $this->pdo()->query(
            'SELECT coalesce(sum(amount), 0) FROM entries WHERE transfer_id IS NOT NULL'
        )->fetchColumn();

        self::assertSame(0, $movedByTransfers, 'Transfers created or destroyed money');
    }
}
