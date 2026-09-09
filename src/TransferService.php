<?php

declare(strict_types=1);

namespace Ledger;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Writes a transfer: one transfers row, two entries summing to zero, two balance
 * updates, one transaction.
 *
 * The method that is missing is the interesting one. Nothing here reads a
 * balance and decides whether the transfer fits. The debit is simply attempted,
 * and if it would take the account below zero the CHECK constraint aborts the
 * transaction — which is why the rejection is immune to the read-then-write race
 * that a pre-flight check would have (ADR-001).
 */
final class TransferService
{
    /** PostgreSQL SQLSTATE codes, from the class 23 integrity constraint family. */
    private const CHECK_VIOLATION = '23514';
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @throws TransferRejected
     */
    public function execute(TransferRequest $request): TransferResult
    {
        $transferId = Uuid::generate();

        $this->pdo->beginTransaction();

        try {
            $this->assertAccountsCanTransact($request);
            $createdAt = $this->recordTransfer($transferId, $request);
            $this->moveBalances($request);
            $this->writeEntries($transferId, $request);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // The key was taken. Note what is not here: a SELECT before the
            // insert, asking whether this key has been seen. Two simultaneous
            // retries would both find nothing and both proceed. Attempting the
            // insert instead makes the unique index the arbiter, exactly as the
            // CHECK constraint is the arbiter of the balance.
            if ($exception instanceof PDOException && $this->isDuplicateIdempotencyKey($exception)) {
                return $this->replay($request);
            }

            throw $exception instanceof PDOException
                ? $this->translate($exception)
                : $exception;
        }

        return new TransferResult(
            $this->describe($transferId, $request->fromAccountId, $request->toAccountId, $request->amount, $request->currency, $createdAt),
            replayed: false,
        );
    }

    /**
     * The key has been used before. Whether that is a retry or a collision is
     * decided by the fingerprint, not by the key (ADR-002).
     *
     * Reaching here means the losing insert already waited: PostgreSQL blocks a
     * conflicting insert until the transaction holding the key commits or aborts,
     * so by the time 23505 is raised the winning row is committed and visible to
     * the read below. There is no window in which the key is taken but the row
     * cannot be found.
     *
     * @throws TransferRejected
     */
    private function replay(TransferRequest $request): TransferResult
    {
        $statement = $this->pdo->prepare(
            'SELECT id, request_fingerprint, from_account_id, to_account_id, amount, currency, created_at
               FROM transfers
              WHERE idempotency_key = :idempotency_key'
        );
        $statement->execute(['idempotency_key' => $request->idempotencyKey]);

        $existing = $statement->fetch();

        if ($existing === false) {
            throw new RuntimeException(
                'Idempotency key ' . $request->idempotencyKey . ' collided but no transfer holds it'
            );
        }

        // Same key, different request. Returning the first result here would let
        // a client silently receive the outcome of a transfer it did not ask for,
        // which is the failure mode ADR-002 exists to refuse.
        if ($existing['request_fingerprint'] !== $request->fingerprint()) {
            throw new TransferRejected('idempotency_key_conflict');
        }

        return new TransferResult(
            $this->describe(
                $existing['id'],
                $existing['from_account_id'],
                $existing['to_account_id'],
                (int) $existing['amount'],
                $existing['currency'],
                $existing['created_at'],
            ),
            replayed: true,
        );
    }

    /**
     * The one place a transfer is turned into a response body, used by both the
     * committed path and the replayed one. §4 requires the two to be identical,
     * and two separate literals would eventually stop being so.
     *
     * @return array{id: string, from: string, to: string, amount: int, currency: string, created_at: string}
     */
    private function describe(
        string $id,
        string $from,
        string $to,
        int $amount,
        string $currency,
        string $createdAt,
    ): array {
        return [
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'currency' => $currency,
            'created_at' => $createdAt,
        ];
    }

    /**
     * Both accounts must exist and all three currencies must agree. §3: a
     * transfer between different currencies is rejected, never converted.
     *
     * This is a read that informs a decision, which is exactly what the balance
     * check must not be — and the difference is that currency is immutable.
     * Nothing updates it, so there is no window between reading it and acting on
     * it in which it could have changed. A balance has such a window, which is
     * why it is left to the constraint instead.
     *
     * @throws TransferRejected
     */
    private function assertAccountsCanTransact(TransferRequest $request): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id, currency FROM accounts WHERE id IN (:from, :to)'
        );
        $statement->execute([
            'from' => $request->fromAccountId,
            'to' => $request->toAccountId,
        ]);

        $currencies = [];
        foreach ($statement->fetchAll() as $row) {
            $currencies[$row['id']] = $row['currency'];
        }

        if (!isset($currencies[$request->fromAccountId], $currencies[$request->toAccountId])) {
            throw new TransferRejected('unknown_account');
        }

        if (
            $currencies[$request->fromAccountId] !== $request->currency
            || $currencies[$request->toAccountId] !== $request->currency
        ) {
            throw new TransferRejected('currency_mismatch');
        }
    }

    /**
     * @return string The database's own created_at, so the response reports when
     *                the ledger accepted the transfer rather than when PHP
     *                started assembling it.
     */
    private function recordTransfer(string $transferId, TransferRequest $request): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfers (
                 id, idempotency_key, request_fingerprint,
                 from_account_id, to_account_id, amount, currency
             ) VALUES (
                 :id, :idempotency_key, :request_fingerprint,
                 :from_account_id, :to_account_id, :amount, :currency
             ) RETURNING created_at'
        );

        $statement->execute([
            'id' => $transferId,
            'idempotency_key' => $request->idempotencyKey,
            'request_fingerprint' => $request->fingerprint(),
            'from_account_id' => $request->fromAccountId,
            'to_account_id' => $request->toAccountId,
            'amount' => $request->amount,
            'currency' => $request->currency,
        ]);

        return (string) $statement->fetchColumn();
    }

    /**
     * The debit is attempted, not authorised. If it would take the account below
     * zero, this statement raises 23514 and the whole transaction is undone.
     */
    private function moveBalances(TransferRequest $request): void
    {
        $updates = [
            ['id' => $request->fromAccountId, 'delta' => -$request->amount],
            ['id' => $request->toAccountId, 'delta' => $request->amount],
        ];

        // Rows are locked in account-id order rather than debit-then-credit
        // order. Two simultaneous transfers in opposite directions between the
        // same pair would otherwise each hold the row the other is waiting for,
        // and one of them would die of a deadlock instead of a real conflict.
        usort($updates, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $statement = $this->pdo->prepare(
            'UPDATE accounts SET balance = balance + :delta WHERE id = :id'
        );

        foreach ($updates as $update) {
            $statement->execute($update);
        }
    }

    /**
     * Both postings in one statement, with the two amounts written as a signed
     * pair. They cannot fail to sum to zero without this line being edited,
     * which is a narrower target than two inserts that must be kept in step.
     */
    private function writeEntries(string $transferId, TransferRequest $request): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO entries (transfer_id, account_id, amount) VALUES (?, ?, ?), (?, ?, ?)'
        );

        $statement->execute([
            $transferId, $request->fromAccountId, -$request->amount,
            $transferId, $request->toAccountId, $request->amount,
        ]);
    }

    /**
     * Turns a constraint violation into a domain refusal. Anything unrecognised
     * is returned untouched: an unexpected database error is a fault, and
     * dressing it up as a business outcome would hide it.
     */
    private function translate(PDOException $exception): Throwable
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        // The 422 in §4, arriving from where the spec says it should: the
        // constraint, inside the transaction that tried to write the entries.
        if ($sqlState === self::CHECK_VIOLATION && str_contains($message, 'balance_non_negative')) {
            return new TransferRejected('insufficient_funds');
        }

        return $exception;
    }

    /**
     * Narrowed to the one unique index a transfer can realistically collide on.
     * A duplicate primary key would be a uuid collision, which is a fault and
     * must not be mistaken for a retry.
     */
    private function isDuplicateIdempotencyKey(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::UNIQUE_VIOLATION
            && str_contains($exception->getMessage(), 'transfers_idempotency_key_key');
    }
}
