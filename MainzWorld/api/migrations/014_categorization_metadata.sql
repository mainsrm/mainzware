ALTER TABLE budget_transactions ADD COLUMN IF NOT EXISTS normalized_merchant TEXT;
ALTER TABLE budget_transactions ADD COLUMN IF NOT EXISTS category_confidence NUMERIC(4,3);
ALTER TABLE budget_transactions ADD COLUMN IF NOT EXISTS categorization_method TEXT;
