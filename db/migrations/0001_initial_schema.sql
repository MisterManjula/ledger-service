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
