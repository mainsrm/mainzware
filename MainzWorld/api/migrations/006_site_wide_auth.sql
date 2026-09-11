-- users table is now the one site-wide MainzWorld login (was cms_users).
ALTER TABLE cms_users RENAME TO users;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
