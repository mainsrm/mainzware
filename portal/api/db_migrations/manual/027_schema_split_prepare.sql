-- Phase 7, step 1 of 2. Superuser prep that MUST run before 027_schema_split.sql.
--   sudo -u postgres psql -d mainzworld --single-transaction -f 027_schema_split_prepare.sql
--
-- PRECONDITION: `php portal/api/bin/migrate_db.php` must report "Already up to
-- date." before you run this. This script puts portal/budget/auth ahead of
-- public on the app role's search_path while every table is still in public, so
-- any migration that is still pending and does an unqualified CREATE TABLE would
-- create it in `portal` instead of `public`. 027 would not catch that: its
-- per-table check only looks in public and the table's own mapped schema.
--
-- Everything here needs privileges the app role cannot exercise from
-- migrate_db.php:
--
--   * ALTER ROLE <other role> SET needs superuser. A role may only alter its
--     own SET options, so mainzworld_app cannot set the scraper's search_path.
--     This is the one genuinely superuser-only reason this file exists.
--   * REVOKE ... FROM PUBLIC on the database is an ownership operation. Done by
--     a non-owner it is a no-op WARNING, not an error -- PDO would never surface
--     it and the deploy would report success having changed nothing.
--
-- NOTE: mainzworld_app OWNS this database (datacl shows mainzworld_app=CTc), so
-- it does hold CREATE on it and could create these schemas itself. That is worth
-- recording as a security fact rather than a convenience: the credential the web
-- app uses on every request can CREATE and DROP SCHEMA, and the public-schema
-- revokes below cannot constrain it. Reducing that is Stage 7b, not this script.
--
-- Idempotent and safe to re-run. Records itself in schema_migrations.

-- Owned by the app role so that 027 (running as mainzworld_app) can create
-- tables in them and move tables into them without further grants.
CREATE SCHEMA IF NOT EXISTS portal AUTHORIZATION mainzworld_app;
CREATE SCHEMA IF NOT EXISTS budget AUTHORIZATION mainzworld_app;
CREATE SCHEMA IF NOT EXISTS auth   AUTHORIZATION mainzworld_app;

-- `public` stays LAST. An unqualified CREATE targets the FIRST schema on the
-- path; keeping public first would mean new tables silently land back in the
-- flat schema. public remains on the path at all because public.schema_migrations
-- deliberately stays there.
--
-- Set before the tables move, not after: while everything is still in public
-- this is a no-op, and afterwards every NEW connection resolves correctly. It
-- does NOT affect sessions already open -- php-fpm must be restarted (see the
-- runbook in db-migrations.md).
ALTER ROLE mainzworld_app SET search_path = portal, budget, auth, public;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_scraper') THEN
        -- Scraper only ever touches portal.sale_properties. Its table-level
        -- grants travel with the table across SET SCHEMA; schema USAGE does not,
        -- so this is the load-bearing line for keeping scrapes working.
        GRANT USAGE ON SCHEMA portal TO mainzworld_scraper;
        -- Prod's grants were made by hand, so a GRANT ALL ON SCHEMA public may
        -- exist here even though local only shows USAGE.
        REVOKE CREATE ON SCHEMA public FROM mainzworld_scraper;
        ALTER ROLE mainzworld_scraper SET search_path = portal, public;
    ELSE
        RAISE NOTICE 'Role mainzworld_scraper not present; skipping its grants.';
    END IF;
END
$$;

-- Default-deny on the flat schema now that it holds nothing but the migration
-- ledger. Verify empirically rather than trusting the PG version: a database
-- restored from a pre-PG15 dump carries the old permissive ACL forward.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
DO $$
BEGIN
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database());

    -- MUST follow that REVOKE. CONNECT is held implicitly via PUBLIC when
    -- pg_database.datacl IS NULL, which is the state a hand-provisioned database
    -- is in until someone grants something. The REVOKE above materializes the
    -- ACL with owner-only rights, and every non-owner role loses CONNECT the
    -- instant it runs -- a full outage, and one that local testing cannot
    -- reproduce if local already has explicit grants. Re-granting is
    -- unconditional so it is correct in both environments.
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO mainzworld_app', current_database());
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_scraper') THEN
        EXECUTE format('GRANT CONNECT ON DATABASE %I TO mainzworld_scraper', current_database());
    END IF;
END
$$;

-- Re-granted explicitly: migrate_db.php still manages public.schema_migrations,
-- and it previously relied on the blanket PUBLIC grant just revoked above.
GRANT CREATE, USAGE ON SCHEMA public TO mainzworld_app;

-- Not needed today: one role creates and owns everything in the new schemas.
-- The moment the app is split into migrator + DML roles (Stage 7b), this file
-- must also carry ALTER DEFAULT PRIVILEGES FOR ROLE <migrator> IN SCHEMA
-- portal, budget, auth -- including USAGE, SELECT on sequences, because SERIAL
-- defaults and lastInsertId()'s lastval() both need them -- or every future
-- table will be invisible to the runtime role.

INSERT INTO public.schema_migrations (filename) VALUES ('manual/027_schema_split_prepare.sql')
ON CONFLICT (filename) DO NOTHING;

-- Verification. Expect: three schemas owned by mainzworld_app, a search_path on
-- each role, no C for PUBLIC on public, and CONNECT held explicitly by both
-- roles. Read this output before running 027.
\echo '--- schemas ---'
SELECT nspname AS schema, pg_get_userbyid(nspowner) AS owner
FROM pg_namespace WHERE nspname IN ('portal','budget','auth','public') ORDER BY 1;
\echo '--- role search_path ---'
SELECT rolname, rolconfig FROM pg_roles
WHERE rolname IN ('mainzworld_app','mainzworld_scraper') ORDER BY 1;
\echo '--- public schema ACL (PUBLIC must not hold C) ---'
SELECT nspacl FROM pg_namespace WHERE nspname = 'public';
\echo '--- database ACL (both app roles must hold c = CONNECT) ---'
SELECT datacl FROM pg_database WHERE datname = current_database();
