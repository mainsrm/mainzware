CREATE TABLE IF NOT EXISTS budget_category_rules (
    id SERIAL PRIMARY KEY,
    budget_id INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    pattern TEXT NOT NULL,
    match_type VARCHAR(20) NOT NULL DEFAULT 'merchant' CHECK (match_type IN ('merchant','description')),
    category VARCHAR(255) NOT NULL,
    priority INTEGER NOT NULL DEFAULT 100,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (budget_id, pattern, match_type)
);
CREATE INDEX IF NOT EXISTS idx_budget_category_rules_budget ON budget_category_rules (budget_id, is_active, priority);
