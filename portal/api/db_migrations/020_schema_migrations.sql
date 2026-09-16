-- Tracks which migration files have been applied to this database, so migrate_db.php
-- can safely skip already-applied files and apply only what's new.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    TEXT PRIMARY KEY,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
