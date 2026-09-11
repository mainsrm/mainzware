-- Budget App Database Schema Upgrade
-- Run this to set up all necessary tables for the budget app

-- Drop existing tables if they exist (be careful in production!)
-- DROP TABLE IF EXISTS transactions CASCADE;
-- DROP TABLE IF EXISTS expenses CASCADE;
-- DROP TABLE IF EXISTS expense_categories CASCADE;
-- DROP TABLE IF EXISTS income CASCADE;
-- DROP TABLE IF EXISTS budget_categories CASCADE;

-- Table for income sources
CREATE TABLE IF NOT EXISTS income (
    id SERIAL PRIMARY KEY,
    category VARCHAR(255) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    percentage NUMERIC(5,2) DEFAULT 0,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for budget categories (Where the Money Goes)
CREATE TABLE IF NOT EXISTS budget_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    budgeted_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    is_savings BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for fixed monthly expenses (bills)
CREATE TABLE IF NOT EXISTS fixed_expenses (
    id SERIAL PRIMARY KEY,
    category VARCHAR(100) NOT NULL, -- HOUSING, UTILITIES, VEHICLES, etc.
    expense_name VARCHAR(255) NOT NULL,
    due_date INTEGER, -- Day of month, 1-31
    amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for variable spending categories
CREATE TABLE IF NOT EXISTS variable_spending (
    id SERIAL PRIMARY KEY,
    parent_category VARCHAR(100) NOT NULL, -- FOOD & GROCERIES, TRANSPORTATION, etc.
    subcategory VARCHAR(255) NOT NULL,
    budgeted_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for transactions (already exists but let's ensure it has all needed columns)
CREATE TABLE IF NOT EXISTS transactions (
    id SERIAL PRIMARY KEY,
    account VARCHAR(50) NOT NULL,
    chkref VARCHAR(50),
    debit NUMERIC(10,2),
    credit NUMERIC(10,2),
    balance NUMERIC(10,2),
    date DATE NOT NULL,
    description TEXT,
    category VARCHAR(255),
    budget_category VARCHAR(255), -- Maps to budget_categories.name
    is_manual BOOLEAN DEFAULT FALSE, -- TRUE if manually entered, FALSE if imported
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(date);
CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions(category);
CREATE INDEX IF NOT EXISTS idx_transactions_budget_category ON transactions(budget_category);
CREATE INDEX IF NOT EXISTS idx_transactions_account ON transactions(account);

-- Category mapping table (maps transaction categories to budget categories)
CREATE TABLE IF NOT EXISTS category_mappings (
    id SERIAL PRIMARY KEY,
    transaction_category VARCHAR(255) NOT NULL,
    budget_category VARCHAR(255) NOT NULL,
    UNIQUE(transaction_category)
);

-- Insert default category mappings
INSERT INTO category_mappings (transaction_category, budget_category) VALUES
('Groceries', 'Groceries'),
('Groceries/Wholesale', 'Groceries'),
('Groceries/Kroger', 'Groceries'),
('Groceries/Walmart', 'Groceries'),
('Fast Food/Dining', 'Dining'),
('Dining Out', 'Dining'),
('Gas/Fuel', 'Transportation'),
('Online Shopping', 'Misc'),
('Subscriptions', 'Subscriptions Memberships'),
('Memberships', 'Subscriptions Memberships'),
('Home Improvement', 'Household'),
('Farm/Home Supply', 'Household'),
('Personal Care', 'Personal'),
('Entertainment', 'Personal'),
('Bank Fees', 'Misc'),
('ATM Withdrawal', 'Misc'),
('Charitable/Religious', 'Giving'),
('Education', 'Education (Tuition)'),
('Tuition', 'Education (Tuition)'),
('Insurance', 'Insurance'),
('Utilities/Electric', 'Utilities'),
('Utilities/Water', 'Utilities'),
('Utilities/Internet', 'Utilities'),
('Utilities/Phone', 'Utilities'),
('Parking', 'Misc'),
('Pet Care', 'Misc')
ON CONFLICT (transaction_category) DO NOTHING;

-- View for monthly spending summary
CREATE OR REPLACE VIEW monthly_spending_summary AS
SELECT 
    COALESCE(budget_category, category, 'Uncategorized') as category,
    DATE_TRUNC('month', date) as month,
    SUM(COALESCE(debit, 0)) as total_spent,
    COUNT(*) as transaction_count
FROM transactions
WHERE debit IS NOT NULL AND debit > 0
GROUP BY COALESCE(budget_category, category, 'Uncategorized'), DATE_TRUNC('month', date)
ORDER BY month DESC, category;

-- View for budget vs actual
CREATE OR REPLACE VIEW budget_vs_actual AS
SELECT 
    bc.name as category,
    bc.budgeted_amount,
    COALESCE(SUM(t.debit), 0) as actual_spent,
    bc.budgeted_amount - COALESCE(SUM(t.debit), 0) as variance,
    CASE 
        WHEN bc.budgeted_amount > 0 THEN 
            ROUND(((COALESCE(SUM(t.debit), 0) - bc.budgeted_amount) / bc.budgeted_amount * 100), 1)
        ELSE 0 
    END as variance_percent
FROM budget_categories bc
LEFT JOIN transactions t ON (t.budget_category = bc.name OR t.category = bc.name)
    AND t.date >= DATE_TRUNC('month', CURRENT_DATE)
    AND t.date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
GROUP BY bc.name, bc.budgeted_amount, bc.sort_order
ORDER BY bc.sort_order;
