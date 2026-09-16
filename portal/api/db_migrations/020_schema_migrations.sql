-- Tracks which migration files have been applied to this database, so migrate_db.php
-- can safely skip already-applied files and apply only what's new.
-- Schema-qualified on purpose: this is infrastructure and must stay in public even
-- once product schemas exist earlier on the search_path.
CREATE TABLE IF NOT EXISTS public.schema_migrations (
    filename    TEXT PRIMARY KEY,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
