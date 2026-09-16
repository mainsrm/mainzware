-- Seed the standard category set for each user-owned budget.
-- Migration 004 created legacy global categories before migration 009 added budget_id.
INSERT INTO budget_categories (budget_id, name, budgeted_amount, sort_order)
SELECT budgets.id, seed.name, 0, seed.sort_order
FROM budgets
CROSS JOIN (
    VALUES
        ('Housing', 1),
        ('Vehicles', 2),
        ('Transportation', 3),
        ('Insurance', 4),
        ('Utilities', 5),
        ('Groceries', 6),
        ('Dining', 7),
        ('Subscriptions Memberships', 8),
        ('Giving', 9),
        ('Household', 10),
        ('Personal', 11),
        ('Education (Tuition)', 12),
        ('Misc', 13),
        ('Transfer', 0)
) AS seed(name, sort_order)
ON CONFLICT (budget_id, name) DO NOTHING;

-- These rows predate budget ownership and must not remain invisible to every budget.
DELETE FROM budget_categories WHERE budget_id IS NULL;
