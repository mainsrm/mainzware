-- Stage 7b, part 1: create the runtime (DML-only) role. Superuser, run by hand:
--   sudo -u postgres psql -d mainzworld --single-transaction -v ON_ERROR_STOP=1 -f runtime_role.sql
--
-- Today mainzworld_app is both the OWNER of every table/schema/the database
-- itself AND the credential the running web app connects with on every
-- request -- see ARCHITECTURE.md's "accepted tradeoff" note and
-- db-migrations.md. This role narrows that: mainzworld_runtime gets
-- SELECT/INSERT/UPDATE/DELETE only, never ALTER/DROP/CREATE. mainzworld_app
-- keeps ownership and becomes the migrator role, used only by
-- bin/migrate_db.php (see Database::migratorConnection()).
--
-- Needs superuser because ALTER DEFAULT PRIVILEGES FOR ROLE mainzworld_app can
-- only be set by mainzworld_app itself or a superuser, and mainzworld_runtime
-- does not exist yet for mainzworld_app to grant to non-interactively without
-- one of them already having CREATEROLE.
--
-- Idempotent and safe to re-run. Records itself in schema_migrations.
-- Depends on 027_schema_split.sql having already run (grants target
-- portal/budget/auth, not public) -- enforced below, not just documented here.
--
-- This script does NOT set a password for mainzworld_runtime (never hardcode
-- a secret in a tracked file). Immediately after running this, set one
-- interactively and put it straight into the host's .env -- not into shell
-- history if avoidable:
--   ALTER ROLE mainzworld_runtime WITH PASSWORD '<generated>';
-- A role with no password cannot authenticate under scram-sha-256/md5, so
-- skipping this step leaves a role that exists but cannot log in.

SET LOCAL lock_timeout = '5s';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.schema_migrations WHERE filename = '027_schema_split.sql') THEN
        RAISE EXCEPTION 'Run 027_schema_split.sql first -- portal/budget/auth do not exist yet.';
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_runtime') THEN
        CREATE ROLE mainzworld_runtime LOGIN;
    END IF;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO mainzworld_runtime', current_database());
END
$$;

GRANT USAGE ON SCHEMA portal, budget, auth TO mainzworld_runtime;

-- Same reasoning as mainzworld_app/mainzworld_scraper in
-- 027_schema_split_prepare.sql: without this, unqualified table names in
-- every Data/*.php query resolve against "$user", public and fail outright.
ALTER ROLE mainzworld_runtime SET search_path = portal, budget, auth, public;

-- Existing objects: ALTER DEFAULT PRIVILEGES only covers objects created AFTER
-- it is set, so every table/sequence that already exists needs an explicit
-- grant here. Loop rather than list by name so this does not silently miss a
-- table added between 027 and whenever this is run.
DO $$
DECLARE
    rec record;
BEGIN
    FOR rec IN
        SELECT schemaname, tablename FROM pg_tables
        WHERE schemaname IN ('portal', 'budget', 'auth')
    LOOP
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I.%I TO mainzworld_runtime',
            rec.schemaname, rec.tablename);
    END LOOP;

    FOR rec IN
        SELECT schemaname, sequencename FROM pg_sequences
        WHERE schemaname IN ('portal', 'budget', 'auth')
    LOOP
        -- USAGE, SELECT (not UPDATE): SERIAL defaults and lastInsertId()'s
        -- lastval() both need this; the role never sets a sequence directly.
        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %I.%I TO mainzworld_runtime',
            rec.schemaname, rec.sequencename);
    END LOOP;
END
$$;

-- Future objects: every table/sequence a migration creates from here on is
-- automatically visible to the runtime role without editing this file again.
-- Keyed FOR ROLE mainzworld_app because that is who runs migrate_db.php and
-- therefore who actually creates the objects -- default privileges are scoped
-- to the creating role, not the schema. If the migrator identity ever changes
-- from mainzworld_app, this must be re-keyed to the new role, and the
-- retroactive grant loop above re-run for objects the old identity already created.
ALTER DEFAULT PRIVILEGES FOR ROLE mainzworld_app IN SCHEMA portal, budget, auth
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO mainzworld_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE mainzworld_app IN SCHEMA portal, budget, auth
    GRANT USAGE, SELECT ON SEQUENCES TO mainzworld_runtime;

-- Defense-in-depth against future drift: pg_tables/pg_sequences above cannot
-- see views, matviews, functions, or custom types. None exist today (verified
-- against the live catalog) -- if that ever changes, this script needs
-- extending with matching GRANT SELECT/EXECUTE statements before it can be
-- trusted again, so fail loud rather than silently under-grant.
DO $$
DECLARE n int;
BEGIN
    SELECT
        (SELECT count(*) FROM pg_views WHERE schemaname IN ('portal','budget','auth'))
      + (SELECT count(*) FROM pg_matviews WHERE schemaname IN ('portal','budget','auth'))
      + (SELECT count(*) FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace
           WHERE ns.nspname IN ('portal','budget','auth'))
      -- Every CREATE TABLE implicitly creates a composite row type of the same
      -- name (typtype='c' with typrelid pointing at the table itself) -- that
      -- is not a standalone type needing its own grant. Only count typtype='c'
      -- entries whose typrelid is a genuine standalone composite (relkind='c'),
      -- plus enums/domains, which have no such shadow.
      + (SELECT count(*) FROM pg_type t JOIN pg_namespace ns ON ns.oid = t.typnamespace
           JOIN pg_class c ON c.oid = t.typrelid
           WHERE ns.nspname IN ('portal','budget','auth') AND t.typtype = 'c' AND c.relkind = 'c')
      + (SELECT count(*) FROM pg_type t JOIN pg_namespace ns ON ns.oid = t.typnamespace
           WHERE ns.nspname IN ('portal','budget','auth') AND t.typtype IN ('e','d'))
    INTO n;
    IF n > 0 THEN
        RAISE EXCEPTION 'Non-table object(s) exist in portal/budget/auth that this script does not grant on -- extend it before re-running.';
    END IF;
END
$$;

INSERT INTO public.schema_migrations (filename) VALUES ('manual/runtime_role.sql')
ON CONFLICT (filename) DO NOTHING;

-- Verification. Expect: mainzworld_runtime present, not superuser, cannot
-- CREATE in any of the three schemas (no C in its ACL entries), and holds a
-- DML grant on every existing table and sequence.
\echo '--- role ---'
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = 'mainzworld_runtime';
\echo '--- schema ACLs (mainzworld_runtime should show only U, never C) ---'
SELECT nspname, nspacl FROM pg_namespace WHERE nspname IN ('portal', 'budget', 'auth');
\echo '--- tables missing a grant (expect 0 rows) ---'
SELECT schemaname, tablename FROM pg_tables t
WHERE schemaname IN ('portal', 'budget', 'auth')
  AND NOT EXISTS (
      SELECT 1 FROM information_schema.role_table_grants g
      WHERE g.table_schema = t.schemaname AND g.table_name = t.tablename
        AND g.grantee = 'mainzworld_runtime' AND g.privilege_type = 'SELECT'
  );
\echo '--- sequences missing a grant (expect 0 rows) ---'
SELECT s.schemaname, s.sequencename FROM pg_sequences s
WHERE s.schemaname IN ('portal', 'budget', 'auth')
  AND NOT EXISTS (
      SELECT 1 FROM information_schema.role_usage_grants g
      WHERE g.object_schema = s.schemaname AND g.object_name = s.sequencename
        AND g.object_type = 'SEQUENCE' AND g.grantee = 'mainzworld_runtime'
  );
\echo '--- reminder: set a password now if this is a fresh role ---'
\echo "ALTER ROLE mainzworld_runtime WITH PASSWORD '<generated>';"
