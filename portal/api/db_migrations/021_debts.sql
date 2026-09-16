-- Debt snowball feature: each row is one debt owned by a single user. The snowball
-- projection is computed on demand from these rows (see Support/SnowballCalculator.php),
-- so no derived/forecast data is stored here.
CREATE TABLE IF NOT EXISTS debts (
    id                  SERIAL PRIMARY KEY,
    user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name                VARCHAR(255) NOT NULL,
    balance             NUMERIC(12, 2) NOT NULL CHECK (balance >= 0),
    min_payment         NUMERIC(12, 2) NOT NULL CHECK (min_payment >= 0),
    -- Annual percentage rate as a percent (e.g. 30.00 = 30%/yr); converted to a
    -- monthly rate in the calculator. 0 means no interest.
    apr                 NUMERIC(6, 3) NOT NULL DEFAULT 0 CHECK (apr >= 0),
    sort_order          INTEGER NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_debts_user_id ON debts (user_id);
