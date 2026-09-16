ALTER TABLE budget_transactions
    ADD COLUMN IF NOT EXISTS import_fingerprint TEXT;

-- Keep the first copy when legacy imported rows are exact duplicates. Manual rows
-- remain outside the fingerprint index and are never removed by this migration.
WITH duplicate_rows AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY budget_id, md5(concat_ws(E'\x1f',
                   transaction_date::text,
                   description,
                   COALESCE(merchant, ''),
                   amount::text,
                   COALESCE(account, ''),
                   COALESCE(chkref, ''),
                   COALESCE(debit::text, ''),
                   COALESCE(credit::text, '')
               ))
               ORDER BY id
           ) AS duplicate_number,
           md5(concat_ws(E'\x1f',
               transaction_date::text,
               description,
               COALESCE(merchant, ''),
               amount::text,
               COALESCE(account, ''),
               COALESCE(chkref, ''),
               COALESCE(debit::text, ''),
               COALESCE(credit::text, '')
           )) AS fingerprint
    FROM budget_transactions
    WHERE budget_id IS NOT NULL AND is_manual = FALSE
)
DELETE FROM budget_transactions transaction
USING duplicate_rows duplicate
WHERE transaction.id = duplicate.id
  AND duplicate.duplicate_number > 1;

UPDATE budget_transactions
SET import_fingerprint = md5(concat_ws(E'\x1f',
    transaction_date::text,
    description,
    COALESCE(merchant, ''),
    amount::text,
    COALESCE(account, ''),
    COALESCE(chkref, ''),
    COALESCE(debit::text, ''),
    COALESCE(credit::text, '')
))
WHERE budget_id IS NOT NULL AND is_manual = FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS idx_budget_transactions_import_fingerprint
    ON budget_transactions (budget_id, import_fingerprint)
    WHERE import_fingerprint IS NOT NULL;