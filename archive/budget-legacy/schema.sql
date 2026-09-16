-- Create database for budget app (run this in phpPgAdmin if needed)
-- CREATE DATABASE budget_app;

-- Use the database
-- \c budget_app;

-- Table for income
CREATE TABLE income (
    id SERIAL PRIMARY KEY,
    category VARCHAR(255) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    notes VARCHAR(255)
);

-- Table for expense categories
CREATE TABLE expense_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
);

-- Table for expenses
CREATE TABLE expenses (
    id SERIAL PRIMARY KEY,
    category_id INTEGER REFERENCES expense_categories(id),
    expense_name VARCHAR(255) NOT NULL,
    due_date INTEGER, -- Day of month, 1-31
    monthly_amount NUMERIC(10,2) NOT NULL,
    notes VARCHAR(255)
);

-- Table for transactions
CREATE TABLE transactions (
    id SERIAL PRIMARY KEY,
    account VARCHAR(50) NOT NULL,
    chkref VARCHAR(50),
    debit NUMERIC(10,2),
    credit NUMERIC(10,2),
    balance NUMERIC(10,2),
    date DATE NOT NULL,
    description TEXT,
    category VARCHAR(255)
);

-- Insert some initial expense categories
INSERT INTO expense_categories (name) VALUES
('HOUSING'),
('UTILITIES'),
('VEHICLES'),
('INSURANCE'),
('SUBSCRIPTIONS/MEMBERSHIPS'),
('OTHER');