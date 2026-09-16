-- Run manually against the `mainzworld` database (psql or a migration runner).
CREATE TABLE IF NOT EXISTS projects (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    url TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO projects (name, description, url, sort_order)
SELECT * FROM (VALUES
    ('Sale Address Mapper', 'Scraped tax/sheriff sale property listings with map links.', '/properties', 2),
    ('Budget Notebook', 'Import bank transactions (CSV/Excel) and auto-categorize spending.', '/budget', 3)
) AS seed(name, description, url, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM projects);
