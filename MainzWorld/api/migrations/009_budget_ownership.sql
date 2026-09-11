CREATE TABLE IF NOT EXISTS budgets (
    id SERIAL PRIMARY KEY,
    owner_id INTEGER NOT NULL REFERENCES users(id),
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS budget_members (
    budget_id INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permission VARCHAR(20) NOT NULL CHECK (permission IN ('owner', 'editor', 'viewer')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (budget_id, user_id)
);

-- Existing records are assigned to the first active user/default budget during migration.
ALTER TABLE budget_transactions ADD COLUMN IF NOT EXISTS budget_id INTEGER REFERENCES budgets(id) ON DELETE CASCADE;
ALTER TABLE budget_categories ADD COLUMN IF NOT EXISTS budget_id INTEGER REFERENCES budgets(id) ON DELETE CASCADE;
ALTER TABLE budget_categories DROP CONSTRAINT IF EXISTS budget_categories_name_key;
CREATE UNIQUE INDEX IF NOT EXISTS idx_budget_categories_budget_name ON budget_categories (budget_id, name);
CREATE INDEX IF NOT EXISTS idx_budget_transactions_budget_id ON budget_transactions (budget_id);
