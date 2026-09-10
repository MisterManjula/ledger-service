<?php

declare(strict_types=1);

namespace Ledger\Tests;

/**
 * The test the design exists for.
 *
 * Every other test in this suite would also pass against an implementation that
 * read the balance, decided the transfer fitted, and then wrote it. That
 * implementation is wrong, and this is the only test that can say so: between
 * the read and the write there is a window, and under contention several
 * requests fall into it and each conclude there is enough money.
 *
 * Here the funds cover exactly half the attempts. Half must commit and half must
 * be refused, with no arithmetic anywhere in the application deciding which.
 */
final class ConcurrencyTest extends LedgerTestCase
{
    private const ATTEMPTS = 20;
    private const AMOUNT = 1_000;
    private const FUNDED_FOR = self::ATTEMPTS / 2;

    public function testHalfOfTheConcurrentTransfersSucceedAndTheBalanceEndsAtZero(): void
    {
        $from = $this->createAccount(self::FUNDED_FOR * self::AMOUNT);
        $to = $this->createAccount(0);

        $requests = [];
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $requests[] = [
                'body' => [
                    'from' => $from,
                    'to' => $to,
                    'amount' => self::AMOUNT,
                    'currency' => 'EUR',
                ],
                // A distinct key each: these are twenty different transfers that
                // happen to be identical in value, not one transfer retried.
                'key' => $this->idempotencyKey("concurrent-$i"),
            ];
        }

        $responses = $this->postTransfersInParallel($requests);

        $statuses = array_count_values(array_column($responses, 'status'));

        self::assertSame(self::FUNDED_FOR, $statuses[201] ?? 0, 'The wrong number of transfers committed');
        self::assertSame(self::FUNDED_FOR, $statuses[422] ?? 0, 'The wrong number of transfers were refused');

        // "Cleanly" is the operative word. A 500 here would mean the constraint
        // fired and nobody translated it, which is a crash wearing the costume
        // of a business rule.
        foreach ($responses as $response) {
            if ($response['status'] === 422) {
                self::assertSame(['error' => 'insufficient_funds'], $response['body']);
            }
        }

        self::assertSame(0, $this->balanceOf($from), 'The funded account did not end at exactly zero');
        self::assertSame(self::FUNDED_FOR * self::AMOUNT, $this->balanceOf($to));
    }

    /**
     * The balance is never observed mid-flight, so the claim that it never went
     * negative cannot be checked by watching. It is checked by the fact that a
     * negative value is unwritable: no account in the database holds one, and no
     * refused attempt left a trace of having tried.
     */
    public function testNothingWasWrittenByTheRefusedAttempts(): void
    {
        $from = $this->createAccount(self::FUNDED_FOR * self::AMOUNT);
        $to = $this->createAccount(0);

        $requests = [];
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $requests[] = [
                'body' => [
                    'from' => $from,
                    'to' => $to,
                    'amount' => self::AMOUNT,
                    'currency' => 'EUR',
                ],
                'key' => $this->idempotencyKey("residue-$i"),
            ];
        }

        $this->postTransfersInParallel($requests);

        // Twenty attempts, half of them refused: exactly ten transfers, twenty
        // postings, and one opening entry on the funded account.
        $transfers = (int) $this->pdo()->query(
            'SELECT count(*) FROM transfers WHERE from_account_id = ' . $this->pdo()->quote($from)
        )->fetchColumn();
        self::assertSame(self::FUNDED_FOR, $transfers, 'A refused attempt left a transfer row behind');

        self::assertSame(self::FUNDED_FOR + 1, $this->countEntries($from), 'Entry count does not match the committed transfers');
        self::assertSame(self::FUNDED_FOR, $this->countEntries($to));

        $lowest = (int) $this->pdo()->query('SELECT min(balance) FROM accounts')->fetchColumn();
        self::assertGreaterThanOrEqual(0, $lowest, 'An account holds a negative balance');
    }
}
