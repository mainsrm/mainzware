-- Extend budget_transactions to match the legacy Budget app's richer schema, plus
-- income, budget category targets, and transaction-category -> budget-category mappings.
ALTER TABLE budget_transactions
    ADD COLUMN IF NOT EXISTS budget_category TEXT,
    ADD COLUMN IF NOT EXISTS is_manual BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS chkref TEXT,
    ADD COLUMN IF NOT EXISTS debit NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS credit NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS balance NUMERIC(12,2);

CREATE TABLE IF NOT EXISTS budget_income (
    id SERIAL PRIMARY KEY,
    category VARCHAR(255) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    percentage NUMERIC(5,2) DEFAULT 0,
    notes VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS budget_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    budgeted_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    is_savings BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS budget_category_mappings (
    id SERIAL PRIMARY KEY,
    transaction_category VARCHAR(255) NOT NULL UNIQUE,
    budget_category VARCHAR(255) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_budget_transactions_budget_category ON budget_transactions (budget_category);

INSERT INTO budget_category_mappings (transaction_category, budget_category) VALUES
    ('Groceries', 'Groceries'),
    ('Dining', 'Dining'),
    ('Fast Food/Dining', 'Dining'),
    ('Gas/Fuel', 'Transportation'),
    ('Online Shopping', 'Misc'),
    ('Subscriptions', 'Subscriptions Memberships'),
    ('Memberships', 'Subscriptions Memberships'),
    ('Home Improvement', 'Household'),
    ('Personal Care', 'Personal'),
    ('Entertainment', 'Personal'),
    ('Bank Fees', 'Misc'),
    ('ATM Withdrawal', 'Misc'),
    ('Charitable/Religious', 'Giving'),
    ('Education', 'Education (Tuition)'),
    ('Tuition', 'Education (Tuition)'),
    ('Insurance', 'Insurance'),
    ('Utilities', 'Utilities'),
    ('Parking', 'Misc'),
    ('Pet Care', 'Misc')
ON CONFLICT (transaction_category) DO NOTHING;

INSERT INTO budget_categories (name, budgeted_amount, sort_order) VALUES
    ('Housing', 0, 1),
    ('Vehicles', 0, 2),
    ('Transportation', 0, 3),
    ('Insurance', 0, 4),
    ('Utilities', 0, 5),
    ('Groceries', 0, 6),
    ('Dining', 0, 7),
    ('Subscriptions Memberships', 0, 8),
    ('Giving', 0, 9),
    ('Household', 0, 10),
    ('Personal', 0, 11),
    ('Education (Tuition)', 0, 12),
    ('Misc', 0, 13),
    ('Transfer', 0, 0)
ON CONFLICT (name) DO NOTHING;
