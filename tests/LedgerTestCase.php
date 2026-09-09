<?php

declare(strict_types=1);

namespace Ledger\Tests;

use Ledger\AccountRepository;
use Ledger\Database;
use Ledger\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests that drive the real HTTP surface.
 *
 * The requests go over the network to the running service rather than to an
 * instantiated TransferService, because the guarantee this project claims is
 * about what the API does, and the translation of a constraint violation into a
 * 422 happens in public/index.php — a layer that a direct service call would
 * never reach.
 *
 * Fixtures, by contrast, are written straight through PDO: creating an account
 * is not an endpoint and is deliberately not in scope.
 */
abstract class LedgerTestCase extends TestCase
{
    private static ?PDO $connection = null;

    /**
     * The same connection settings the service itself uses. A test suite that
     * connected more leniently could pass against a configuration that the real
     * application would fail on.
     */
    protected function pdo(): PDO
    {
        return self::$connection ??= Database::connect();
    }

    protected function baseUrl(): string
    {
        return getenv('LEDGER_BASE_URL') ?: 'http://localhost:8080';
    }

    /**
     * Creates an account funded with an opening entry, the way db/seed.sql does,
     * so that fixtures satisfy the double-entry invariant rather than being an
     * exception to it.
     */
    protected function createAccount(int $openingBalance, string $currency = 'EUR'): string
    {
        $id = Uuid::generate();

        $statement = $this->pdo()->prepare(
            'INSERT INTO accounts (id, currency, balance) VALUES (:id, :currency, :balance)'
        );
        $statement->execute([
            'id' => $id,
            'currency' => $currency,
            'balance' => $openingBalance,
        ]);

        // entries_amount_nonzero forbids a zero-amount posting, so an account
        // opened empty simply has no opening entry. Its balance and its (empty)
        // sum of entries still agree, which is all the invariant asks.
        if ($openingBalance !== 0) {
            $entry = $this->pdo()->prepare(
                'INSERT INTO entries (transfer_id, account_id, amount) VALUES (NULL, :account_id, :amount)'
            );
            $entry->execute(['account_id' => $id, 'amount' => $openingBalance]);
        }

        return $id;
    }

    protected function balanceOf(string $accountId): int
    {
        $account = (new AccountRepository($this->pdo()))->find($accountId);

        self::assertNotNull($account, "Account $accountId does not exist");

        return $account['balance'];
    }

    protected function countEntries(string $accountId): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT count(*) FROM entries WHERE account_id = :account_id'
        );
        $statement->execute(['account_id' => $accountId]);

        return (int) $statement->fetchColumn();
    }

    protected function transferExists(string $idempotencyKey): bool
    {
        $statement = $this->pdo()->prepare(
            'SELECT count(*) FROM transfers WHERE idempotency_key = :key'
        );
        $statement->execute(['key' => $idempotencyKey]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * A key unique to this run, so that tests never collide with each other or
     * with rows left behind by an earlier run.
     */
    protected function idempotencyKey(string $label): string
    {
        return sprintf('test-%s-%s', $label, Uuid::generate());
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function postTransfer(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/transfers', $body, $idempotencyKey);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function getAccount(string $accountId): array
    {
        return $this->request('GET', '/accounts/' . $accountId);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $handle = curl_init($this->baseUrl() . $path);
        $headers = ['Accept: application/json'];

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        self::assertSame('', $error, "Request to $method $path failed: $error");

        return [
            'status' => $status,
            'body' => json_decode((string) $response, true, 8, JSON_THROW_ON_ERROR),
        ];
    }
}
