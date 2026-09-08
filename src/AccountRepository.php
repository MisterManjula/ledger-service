<?php

declare(strict_types=1);

namespace Ledger;

use PDO;

/**
 * Reads of the accounts table.
 *
 * Note what is absent: there is no method that reads a balance in order to decide
 * whether a transfer may proceed. That check belongs to the CHECK constraint, and
 * offering a convenient way to perform it in PHP instead is how the guarantee
 * would quietly stop being structural (ADR-001).
 */
final class AccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{id: string, currency: string, balance: int}|null
     *         Null when no account carries this id.
     */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, currency, balance FROM accounts WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => $row['id'],
            'currency' => $row['currency'],
            // Explicit, even though pdo_pgsql already returns a native int here.
            // Money leaving this class is an integer in the currency's minor unit
            // and never anything else.
            'balance' => (int) $row['balance'],
        ];
    }
}
