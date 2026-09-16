-- Manual, one-time hardening step. NOT auto-applied by migrate_db.php (it only
-- globs *.sql directly under db_migrations, not this manual/ subfolder), because
-- CREATE ROLE needs superuser and the app's migration runner intentionally
-- doesn't have that privilege.
--
-- Today the Python scraper subprocess (services/property-scraper) inherits the
-- full MAINZWORLD_DB_USER app credential even though it only ever touches
-- sale_properties. This role scopes it down.
--
-- Usage:
--   1. psql -f scoped_scraper_role.sql
--   2. ALTER ROLE mainzworld_scraper WITH PASSWORD '...';  -- pick one, never commit it
--   3. Set MAINZWORLD_SCRAPER_DB_USER / MAINZWORLD_SCRAPER_DB_PASSWORD in the
--      API's .env. SaleScraper::scrape() passes these to the scraper
--      subprocess only; the PHP background worker keeps using the main app
--      credential since it also updates scrape_sources.
--
-- Not tracked or run by migrate_db.php (see db-migrations.md), so this script
-- records its own row in schema_migrations at the end -- check that table to
-- see whether a given database has already had this applied.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_scraper') THEN
        CREATE ROLE mainzworld_scraper LOGIN;
    END IF;
    -- current_database() so this works unedited against any environment's DB name.
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO mainzworld_scraper', current_database());
END
$$;

GRANT USAGE ON SCHEMA public TO mainzworld_scraper;
GRANT SELECT, INSERT, UPDATE ON sale_properties TO mainzworld_scraper;
GRANT USAGE, SELECT ON SEQUENCE sale_properties_id_seq TO mainzworld_scraper;

-- schema_migrations must already exist (migrate_db.php creates it on first run).
INSERT INTO schema_migrations (filename) VALUES ('manual/scoped_scraper_role.sql')
ON CONFLICT (filename) DO NOTHING;
