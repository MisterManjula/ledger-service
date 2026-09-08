-- Two accounts with a starting balance, so the endpoints can be exercised
-- straight after `docker compose up`.
--
-- The ids and amounts are the ones the README uses in its examples: transferring
-- 2500 from the first account to the second leaves it at 47500.
--
-- Re-runnable. Running it twice does not double the balances.

INSERT INTO accounts (id, currency, balance) VALUES
    ('8e1b0f3a-2c44-4b9e-9a1d-6f0c5b2e7a10', 'EUR', 50000),
    ('c2049d17-8b3e-4f52-a6c7-1e40d9b8c331', 'EUR', 50000)
ON CONFLICT (id) DO NOTHING;

-- The opening entries that back those balances, so that the double-entry
-- invariant (balance = sum of the account's entries) holds for seeded accounts
-- too, not just for ones that only ever moved money through transfers.
INSERT INTO entries (transfer_id, account_id, amount) VALUES
    (NULL, '8e1b0f3a-2c44-4b9e-9a1d-6f0c5b2e7a10', 50000),
    (NULL, 'c2049d17-8b3e-4f52-a6c7-1e40d9b8c331', 50000)
ON CONFLICT (account_id) WHERE transfer_id IS NULL DO NOTHING;
