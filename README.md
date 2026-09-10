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

Three routes, twelve tests, two ADRs explaining the reasoning. Scope is
deliberately small: see [Deliberate limitations](#deliberate-limitations) for what
was left out and why.

---

## Running it

```bash
docker compose up
```

The API is available on `http://localhost:8080`. Two accounts are seeded with a
starting balance of `50000` each, so the endpoints can be exercised immediately.

```bash
docker compose exec app vendor/bin/phpunit
```

The schema is applied before the server accepts its first request:
`docker/entrypoint.sh` runs `bin/migrate`, which applies every
`db/migrations/*.sql` not yet recorded in `schema_migrations`, in filename order.
A fresh clone therefore comes up working rather than returning `500`s until
someone migrates by hand. Each migration and the row recording it commit
together, so one that fails halfway leaves neither schema changes nor a version
marker.

Demo data is separate and opt-in. `bin/seed` runs only when `SEED_ON_START` is
`true`, which `docker-compose.yml` sets and nothing else does — seeding on every
start is correct for a throwaway environment and for nothing else.

### What the runtime is

The app container runs PHP's built-in development server, `php -S`, with
`PHP_CLI_SERVER_WORKERS=20`. This is not a production server and is not presented
as one; the subject of this repository is the database constraint, not the
deployment topology.

The worker count is load-bearing rather than arbitrary. Each worker serves one
request at a time, so it is the ceiling on how many transactions can genuinely
reach the same row at once. At `4`, the concurrency test was observed to miss a
deliberately broken implementation once in seven runs. At `20` it did not.

---

## API

### `POST /transfers`

Moves an amount between two accounts. Amounts are integers in the currency's
minor unit — cents, never floats.

```http
POST /transfers
Idempotency-Key: 7f3a1c2e-...
Content-Type: application/json

{
  "from": "8e1b0f3a-2c44-4b9e-9a1d-6f0c5b2e7a10",
  "to": "c2049d17-8b3e-4f52-a6c7-1e40d9b8c331",
  "amount": 2500,
  "currency": "EUR"
}
```

```json
{
  "id": "632232dd-06ff-4441-8d25-094265db5bad",
  "from": "8e1b0f3a-2c44-4b9e-9a1d-6f0c5b2e7a10",
  "to": "c2049d17-8b3e-4f52-a6c7-1e40d9b8c331",
  "amount": 2500,
  "currency": "EUR",
  "created_at": "2026-09-09 08:27:07.157203+00"
}
```

| Status | Meaning |
|---|---|
| `201` | Transfer committed |
| `200` | Key already seen with an identical payload — the original result is returned |
| `409` | Key already seen with a different payload |
| `422` | The request was understood and the ledger refused it |
| `400` | The request could not be understood; nothing was attempted |

The `200` body is byte-identical to the `201` that preceded it — same transfer id,
same `created_at`. It is read back from the stored row rather than rebuilt from
the retry, because a rebuilt body would look entirely plausible and describe a
transfer that never happened. The status is the only place the difference is
reported, so a client that ignores it still reads a correct result.

The `Idempotency-Key` header is required. Amounts are validated as JSON integers:
`"2500"`, `25.5` and any number above `PHP_INT_MAX` are all `400`, because a value
that has passed through a float has already lost the guarantee the rest of the
system maintains.

### `GET /accounts/{id}`

```json
{
  "id": "8e1b0f3a-2c44-4b9e-9a1d-6f0c5b2e7a10",
  "currency": "EUR",
  "balance": 47500
}
```

`404` for an unknown account. An `id` that is not a uuid also returns `404` rather
than reaching PostgreSQL and coming back as a type error.

### `GET /`

A liveness check. It deliberately opens no database connection, so it keeps
answering when PostgreSQL is unreachable instead of reporting a fault it has not
checked for.

### Error codes

Errors are always `{"error": "<code>"}`.

| Code | Status | Meaning |
|---|---|---|
| `insufficient_funds` | `422` | The debit would take the account below zero. Raised by the constraint, not by a balance read |
| `currency_mismatch` | `422` | The accounts and the request do not agree on one currency. No conversion is performed |
| `unknown_account` | `422` | One of the two accounts does not exist |
| `idempotency_key_conflict` | `409` | The key has been used for a different request |
| `missing_idempotency_key` | `400` | Header absent or empty |
| `malformed_json` | `400` | Body is not a JSON object |
| `invalid_from`, `invalid_to` | `400` | Not a uuid |
| `invalid_amount` | `400` | Not a positive JSON integer |
| `invalid_currency` | `400` | Not three uppercase letters |
| `same_account` | `400` | Source and destination are the same |
| `account_not_found` | `404` | `GET /accounts/{id}` on an id that does not exist |
| `not_found` | `404` | No route matches this method and path |
| `internal_error` | `500` | A fault. Logged in full, reported as a bare code |

---

## Data model

Three tables. `accounts` holds the materialised balance, `entries` holds the
individual postings, `transfers` holds the idempotency record.

```sql
-- Initial schema: accounts, transfers, entries.
--
-- Money is always a bigint in the currency's minor unit (cents). There is no
-- numeric/float column anywhere in this schema, so no rounding decision is ever
-- delegated to the database or to PHP.

CREATE TABLE accounts (
    id         uuid PRIMARY KEY,
    currency   char(3)     NOT NULL,
    balance    bigint      NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),

    -- The reason this project exists. Every write path reaches the balance
    -- through this constraint, including one added later by someone who never
    -- read the service layer.
    CONSTRAINT balance_non_negative CHECK (balance >= 0)
);

-- One row per accepted transfer attempt. The idempotency key identifies the
-- attempt, not the transfer, so it is a separate column from the primary key
-- rather than being reused as one (ADR-002).
CREATE TABLE transfers (
    id                  uuid PRIMARY KEY,
    idempotency_key     text        NOT NULL,
    -- Hash of the canonical request payload. Lets a replayed key be told apart
    -- from a reused key carrying different values, which is a 409 and not a 200.
    request_fingerprint char(64)    NOT NULL,
    from_account_id     uuid        NOT NULL REFERENCES accounts (id),
    to_account_id       uuid        NOT NULL REFERENCES accounts (id),
    amount              bigint      NOT NULL,
    currency            char(3)     NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),

    -- Under concurrency this unique index, not an application-level check, is
    -- what makes two simultaneous retries of the same key collide.
    CONSTRAINT transfers_idempotency_key_key UNIQUE (idempotency_key),
    CONSTRAINT transfers_amount_positive CHECK (amount > 0),
    CONSTRAINT transfers_accounts_distinct CHECK (from_account_id <> to_account_id)
);

-- The postings themselves. Signed: negative debits the account, positive credits
-- it. The two entries of a transfer sum to zero.
CREATE TABLE entries (
    id          bigserial PRIMARY KEY,
    -- NULL marks an opening balance: money that entered the ledger when the
    -- account was created rather than through a transfer. Without it an account
    -- could not be funded at all, since funding it from another account would
    -- have to drive that one negative.
    transfer_id uuid        REFERENCES transfers (id),
    account_id  uuid        NOT NULL REFERENCES accounts (id),
    amount      bigint      NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT entries_amount_nonzero CHECK (amount <> 0)
);

CREATE INDEX entries_account_id_idx ON entries (account_id);
CREATE INDEX entries_transfer_id_idx ON entries (transfer_id);

-- At most one opening balance per account. Also what makes the seed script
-- re-runnable: a second seed conflicts here instead of double-funding.
CREATE UNIQUE INDEX entries_one_opening_per_account_idx
    ON entries (account_id) WHERE transfer_id IS NULL;
```

The `CHECK` is the whole point of the project. Every write path — current or
future — passes through it, because it lives in the database rather than in a
service method that a new caller might not go through.

Each transfer writes two entries that sum to zero and updates both balances in
the same transaction. If either balance would go negative, the transaction
aborts and nothing is written — no transfer row, no entries, no balance change.

The two balance updates are issued in account-id order rather than
debit-then-credit order. Two simultaneous transfers in opposite directions
between the same pair would otherwise each hold the row the other is waiting for.

### Opening balances

`entries.transfer_id` is nullable, and `NULL` marks money that entered the ledger
when the account was created rather than through a transfer.

Without it an account could not be funded at all: funding it from another account
would have to drive that one negative, which is exactly what the constraint
forbids. The partial unique index allows at most one such entry per account,
which is also what makes the seed script safe to run twice — a second run
conflicts there instead of doubling the balances.

---

## Tests

```bash
docker compose exec app vendor/bin/phpunit
```

Twelve test methods across the five scenarios the design has to satisfy.

| Test | What it proves |
|---|---|
| Overdraft | An overdrawing transfer is rejected; balances unchanged; no transfer row and no entries written |
| Exact balance | A transfer of the entire balance is accepted and leaves the account at zero |
| Idempotent retry | Same key, same payload — one movement, and the original body returned verbatim |
| Reordered payload | The fingerprint covers values, not bytes: a client that reorders its JSON is still retrying |
| Key reuse conflict | Same key, different amount or reversed direction — `409`, nothing written |
| Concurrent retry | Eight identical requests at once resolve to exactly one commit |
| Concurrency | Twenty simultaneous transfers against funds for ten — exactly ten succeed, the rest are refused cleanly, the account ends at zero |
| Double-entry invariant | Entries per transfer sum to zero; each balance equals the sum of its entries; transfers create no money |

The concurrency test is the one that matters. Every other test would also pass
against an implementation that reads the balance, decides the transfer fits, and
then writes it — and that implementation is wrong.

Each test was confirmed to fail for a real reason before being kept. Dropping the
`CHECK` constraint makes the overdraft test report a `201` where it expects `422`.
Writing one entry instead of two breaks all three invariant assertions. Disabling
the fingerprint comparison turns both conflict tests into `200`s. Replacing the
constraint with a pre-flight balance read makes the concurrency test commit
thirteen transfers where ten were funded.

---

## Decisions

- [ADR-001 — Balance as a constrained column, not a derived sum](docs/adr-001-balance-as-constrained-column.md)
- [ADR-002 — Why the idempotency key is not the transaction id](docs/adr-002-idempotency-key-not-transaction-id.md)

---

## Deliberate limitations

No authentication. No pagination. No currency conversion — the `currency` field
exists so that a mismatch can be rejected, not so that a rate can be applied. No
outbox, no webhooks, no reconciliation job. No ORM: the constraints are the
subject of the project, so the SQL is hand-written and visible rather than
generated.

These are omissions, not oversights. The scope was chosen so that the two
guarantees above could be implemented properly and tested under contention,
rather than sketched across a wider surface.

---

## Stack

PHP 8.3 · PostgreSQL 16 · Docker Compose · PHPUnit
