ALTER TABLE budget_categories
    ADD COLUMN IF NOT EXISTS parent_category_id INTEGER REFERENCES budget_categories(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_budget_categories_parent ON budget_categories (budget_id, parent_category_id);