# ledger-service
Double-entry ledger where a negative balance is structurally impossible and retries are safe. PHP 8.3 · PostgreSQL · Docker.


# Ledger service

A minimal double-entry ledger built around two guarantees that hold regardless of
what the calling code does.

**A negative balance cannot be written.** The balance is a constrained column, not
a value the application checks before writing. An overdrawing transfer is rejected
by PostgreSQL inside the same transaction that writes the entries — there is no
code path that can bypass it, including one added later by someone who did not read
this file.

**The same transfer, sent twice, moves money once.** Requests carry an idempotency
key that identifies the *attempt*, not the business entity. Reusing a key with a
different payload is a client error and is reported as one, rather than silently
resolving to whichever request arrived first.

Two endpoints, five tests, two ADRs explaining the reasoning. Scope is deliberately
small: see [docs/](docs/) for what was left out and why.

---

## Running it

```bash
docker compose up
```

The API is available on `http://localhost:8080`. Two accounts are seeded with a
starting balance so the endpoints can be exercised immediately.

```bash
docker compose exec app vendor/bin/phpunit
```

---

## API

### `POST /transfers`

Moves an amount between two accounts. Amounts are integers in the currency's
minor unit — cents, never floats.

```http
POST /transfers
Idempotency-Key: 7f3a1c2e-...

{
  "from": "8e1b...",
  "to": "c204...",
  "amount": 2500,
  "currency": "EUR"
}
```

| Status | Meaning |
|---|---|
| `201` | Transfer committed |
| `200` | Key already seen with an identical payload — the original result is returned |
| `409` | Key already seen with a different payload |
| `422` | Insufficient funds |

### `GET /accounts/{id}`

```json
{
  "id": "8e1b...",
  "balance": 47500,
  "currency": "EUR"
}
```

---

## Data model

Three tables. `accounts` holds the materialised balance, `entries` holds the
individual postings, `transfers` holds the idempotency record.

```sql
CREATE TABLE accounts (
    id         uuid PRIMARY KEY,
    currency   char(3)     NOT NULL,
    balance    bigint      NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT balance_non_negative CHECK (balance >= 0)
);
```

The `CHECK` is the whole point of the project. Every write path — current or
future — passes through it, because it lives in the database rather than in a
service method that a new caller might not go through.

Each transfer writes two entries that sum to zero and updates both balances in
the same transaction. If either balance would go negative, the transaction
aborts and nothing is written.

---

## Tests

| Test | What it proves |
|---|---|
| Overdraft | An overdrawing transfer is rejected; balances are unchanged |
| Idempotent retry | Same key, same payload, twice — one movement, one result |
| Key reuse conflict | Same key, different payload — `409`, nothing written |
| Concurrency | N simultaneous transfers with funds for half of them — exactly half succeed, the balance never goes negative |
| Double-entry invariant | Entries per transfer sum to zero; each balance equals the sum of its entries |

The concurrency test is the one that matters. It is the only one that shows the
constraint holds under contention rather than in a happy path.

---

## Decisions

- [ADR-001 — Balance as a constrained column, not a derived sum](docs/adr-001-balance-as-constrained-column.md)
- [ADR-002 — Why the idempotency key is not the transaction id](docs/adr-002-idempotency-key-not-transaction-id.md)

---

## Deliberate limitations

No authentication. No pagination. No currency conversion — the `currency` field
exists so that a mismatch can be rejected, not so that a rate can be applied. No
outbox, no webhooks, no reconciliation job.

These are omissions, not oversights. The scope was chosen so that the two
guarantees above could be implemented properly and tested under contention,
rather than sketched across a wider surface.

---

## Stack

PHP 8.3 · PostgreSQL 16 · Docker Compose · PHPUnit
