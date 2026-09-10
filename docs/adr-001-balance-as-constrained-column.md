# ADR-001 — Balance as a constrained column, not a derived sum

**Status:** accepted, 2026-09-10

## Context

An account may never go negative. The question is not whether to enforce that —
it is where the rule lives, because that choice decides what can bypass it.

A ledger already contains the balance twice over: once as whatever the account
row says, and once as the sum of the postings that explain it. Choosing which of
those is authoritative is the central design decision of this repository, and
everything else follows from it.

## Decision

`accounts.balance` is a materialised `bigint` column carrying a table
constraint:

```sql
CONSTRAINT balance_non_negative CHECK (balance >= 0)
```

Both balances are updated inside the same transaction that writes the two
entries. A transfer that would overdraw is not refused by the application; the
`UPDATE` is attempted, PostgreSQL raises `23514`, and the transaction is undone
in full. The boundary catches that SQLSTATE and translates it into `422`.

## Alternatives considered

**Compute the balance as `SUM(entries.amount)` on read.** This is the more
elegant model, and it is correct by construction: the balance cannot disagree
with the entries, because it *is* the entries. There is no redundancy to drift.

It was rejected for two reasons, only the second of which is decisive.

The first is cost: the sum is unbounded work that grows with the account's
history, on the hottest read in the system. That alone is not disqualifying —
running balance snapshots or a rollup table solve it, at the price of
reintroducing exactly the redundancy the model was chosen to avoid.

The second is that **the rule cannot be expressed**. There is no way to declare
to PostgreSQL that an aggregate over another table may never be negative. `CHECK`
constraints cannot contain subqueries, so the guarantee has nowhere to live in
the schema. It would have to be enforced by whoever writes the entries — which
means enforced by convention, in every present and future write path.

**A `CONSTRAINT TRIGGER` over the entries table.** This is the honest rebuttal to
the paragraph above, and it does work: a deferred constraint trigger can sum an
account's entries at commit time and raise if the result is negative. It is
rejected not as impossible but as worse. It reintroduces the unbounded sum, this
time on the write path; it is application logic wearing a schema costume, harder
to read than a `CHECK` and invisible in `\d accounts`; and its locking behaviour
under contention is considerably subtler than a row update's.

**An application-level check: read the balance, decide, then write.** This is the
version that looks correct and is not, and it is the reason the concurrency test
exists. Between the read and the write there is a window. Under contention
several requests fall into it, each sees sufficient funds, and each proceeds.
Removing the constraint and substituting this check makes the concurrency test
commit thirteen transfers where ten were funded — the test was run that way
deliberately, and that is the number it reported.

## Consequences

**The guarantee is no longer the application's to keep.** No service method, no
migration, no future caller, and no manual `psql` session can write a negative
balance. This is the whole return on the decision.

**The redundant value can drift.** The column and the entries are two statements
about the same fact, and nothing structural forces them to agree — only the
transaction boundary and the correctness of `TransferService`. This is the price
paid, and it is why the double-entry invariant test asserts, across the entire
database, that every balance equals the sum of its entries. That test is not
decoration; it is the other half of this ADR.

**The error path now depends on a database error code.** The `422` is produced by
matching SQLSTATE `23514` and the constraint name inside the exception message.
Coupling a status code to a PostgreSQL message string is not pretty, and renaming
the constraint would silently turn insufficient funds into a `500`. The
alternative — a pre-flight check to produce a friendly error, with the constraint
as a backstop — was rejected because it reintroduces the race for the common
case and leaves the constraint untested in practice.

**The constraint says no without saying why.** It reports that a row was invalid,
not which business rule was violated. With one `CHECK` on the table this is
unambiguous; a second one would make the translation ambiguous, and the mapping
would need to become explicit rather than inferred.

**Every transfer touching an account serialises on that account's row.** A hot
account is a contention point, and this cost is real and absent from the
derived-sum design, where concurrent writers never conflict. For this ledger that
is the right trade: serialising on the row is precisely what makes the refusal
correct rather than probable.
