<?php

declare(strict_types=1);

namespace Ledger;

use JsonException;

/**
 * A POST /transfers body that has been checked. Once one of these exists, its
 * fields are known to be well-formed, which is why TransferService takes one of
 * these rather than five strings and an int in an order easy to get wrong.
 *
 * Validation here answers "can this be understood", never "should this be
 * allowed": no balance is consulted, because that decision belongs to the
 * database (ADR-001).
 */
final class TransferRequest
{
    private function __construct(
        public readonly string $idempotencyKey,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    /**
     * @throws InvalidRequest
     */
    public static function fromJson(string $body, ?string $idempotencyKey): self
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new InvalidRequest('missing_idempotency_key');
        }

        try {
            // Decoded to an object rather than an associative array so that a
            // JSON list can be told apart from a JSON object: with assoc decoding
            // both arrive as PHP arrays, and `[1,2]` would be reported as a
            // missing "from" field instead of as the wrong shape entirely.
            $decoded = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidRequest('malformed_json');
        }

        if (!is_object($decoded)) {
            throw new InvalidRequest('malformed_json');
        }

        $payload = (array) $decoded;

        $from = self::accountId($payload, 'from');
        $to = self::accountId($payload, 'to');

        if ($from === $to) {
            throw new InvalidRequest('same_account');
        }

        return new self($idempotencyKey, $from, $to, self::amount($payload), self::currency($payload));
    }

    /**
     * Identifies the request, not the transfer. Two bodies that describe the same
     * movement hash the same; changing any field changes the hash, which is what
     * lets a replayed key be told apart from a reused one (ADR-002).
     *
     * The field order is fixed by the literal below rather than by the order the
     * keys happened to arrive in, so a client that serialises its JSON
     * differently on retry still produces a matching fingerprint.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'from' => $this->fromAccountId,
            'to' => $this->toAccountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $payload
     * @throws InvalidRequest
     */
    private static function accountId(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new InvalidRequest('invalid_' . $field);
        }

        // Normalised, so that the same account written in two cases produces one
        // fingerprint rather than two.
        return strtolower($value);
    }

    /**
     * @param array<mixed> $payload
     * @throws InvalidRequest
     */
    private static function amount(array $payload): int
    {
        $value = $payload['amount'] ?? null;

        // is_int, not is_numeric. "2500" and 2500.0 are both rejected: a float
        // has already passed through a representation that cannot hold every
        // integer exactly, and accepting it here is how a rounding error enters
        // a system that has no other floats in it. A JSON number larger than
        // PHP_INT_MAX also decodes to a float, so this rejects overflow too.
        if (!is_int($value)) {
            throw new InvalidRequest('invalid_amount');
        }

        // Zero moves nothing; a negative amount is a transfer in the opposite
        // direction wearing a disguise, and would bypass the direction the
        // caller actually asked for.
        if ($value <= 0) {
            throw new InvalidRequest('invalid_amount');
        }

        return $value;
    }

    /**
     * @param array<mixed> $payload
     * @throws InvalidRequest
     */
    private static function currency(array $payload): string
    {
        $value = $payload['currency'] ?? null;

        if (!is_string($value) || preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new InvalidRequest('invalid_currency');
        }

        return $value;
    }
}
