-- Reverse of 027. Run as superuser, attended:
--   sudo -u postgres psql -d mainzworld --single-transaction -f 027_schema_split_revert.sql
--
-- --single-transaction is REQUIRED, not cosmetic. These are ~8 top-level
-- statements. If DROP SCHEMA ... RESTRICT refuses (its entire purpose), an
-- unwrapped run leaves the tables already back in public, both search_paths
-- already reset, the schemas still present and the schema_migrations rows not
-- yet deleted -- a state that is neither split nor reverted. DROP SCHEMA and
-- ALTER ROLE are both transactional in PostgreSQL, so wrapping is sufficient.
-- It also makes the SET LOCAL timeouts below actually take effect; outside a
-- transaction block they are a no-op WARNING.
--
-- VALIDITY WINDOW: only correct while 027 is the newest applied migration. It
-- moves everything out of the three schemas, not just the mapped 18, and deletes
-- only the 027 rows. If a 028+ has since created tables there, they would be
-- dumped into public while still recorded as applied, and a later re-apply of
-- 027 would fail on the straggler check.
--
-- Use this when the split applied cleanly but the application misbehaves. It is
-- a structural reverse, NOT a data restore -- if 027 itself failed, the runner
-- already rolled it back and you need nothing. If data is wrong, restore the
-- pg_dump instead.
--
-- Superuser because resetting mainzworld_scraper's search_path is ALTER ROLE on
-- another role. Idempotent; safe to run twice.

DO $$
DECLARE
    rec        record;
    moved      int := 0;
    collisions text;
    leftovers  text;
BEGIN
    PERFORM set_config('lock_timeout', '5s', true);
    PERFORM set_config('statement_timeout', '120s', true);

    -- Pre-scan so the operator sees every collision at once. Aborting on the
    -- first one turns a single diagnosis into a guess-and-retry loop.
    SELECT string_agg(format('%s.%s', t.schemaname, t.tablename), ', ' ORDER BY t.tablename)
    INTO collisions
    FROM pg_tables t
    WHERE t.schemaname IN ('portal', 'budget', 'auth')
      AND EXISTS (SELECT 1 FROM pg_tables p
                  WHERE p.schemaname = 'public' AND p.tablename = t.tablename);

    IF collisions IS NOT NULL THEN
        -- A same-named table in public would be shadowed and silently diverge.
        -- Refuse rather than merge.
        RAISE EXCEPTION 'public already holds table(s) matching: %. Resolve before reverting.', collisions;
    END IF;

    FOR rec IN
        SELECT schemaname, tablename
        FROM pg_tables
        WHERE schemaname IN ('portal', 'budget', 'auth')
        ORDER BY schemaname, tablename
    LOOP
        EXECUTE format('ALTER TABLE %I.%I SET SCHEMA public', rec.schemaname, rec.tablename);
        moved := moved + 1;
    END LOOP;

    RAISE NOTICE 'Reverted % table(s) to public.', moved;

    SELECT string_agg(format('%s.%s', schemaname, tablename), ', ')
    INTO leftovers
    FROM pg_tables WHERE schemaname IN ('portal', 'budget', 'auth');

    IF leftovers IS NOT NULL THEN
        RAISE EXCEPTION 'Tables still outside public after revert: %', leftovers;
    END IF;
END
$$;

ALTER ROLE mainzworld_app SET search_path = public;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_scraper') THEN
        ALTER ROLE mainzworld_scraper SET search_path = public;
    END IF;
END
$$;

-- RESTRICT, never CASCADE: if anything unexpected still lives in these schemas
-- this must fail loudly, not drop it. The DO block above has already proven
-- they hold no tables.
DROP SCHEMA IF EXISTS portal RESTRICT;
DROP SCHEMA IF EXISTS budget RESTRICT;
DROP SCHEMA IF EXISTS auth   RESTRICT;

-- Without this, migrate_db.php considers 027 applied and will never re-run it.
-- The ARM row goes too, so a re-apply has to be armed deliberately again.
DELETE FROM public.schema_migrations
WHERE filename IN ('027_schema_split.sql',
                   'manual/027_schema_split_prepare.sql',
                   'manual/027_schema_split_ARM');

-- Deliberately NOT reverted: `REVOKE CREATE ON SCHEMA public FROM PUBLIC` and
-- `REVOKE ALL ON DATABASE ... FROM PUBLIC` from the prepare script. They are
-- independent hardening, nothing in the app depends on those blanket grants
-- (mainzworld_app holds CREATE on public explicitly), and re-granting them would
-- make a rollback quietly re-open the database to every role.

\echo '--- tables remaining outside public (expect 0 rows) ---'
SELECT schemaname, tablename FROM pg_tables
WHERE schemaname IN ('portal','budget','auth');
\echo '--- role search_path ---'
SELECT rolname, rolconfig FROM pg_roles
WHERE rolname IN ('mainzworld_app','mainzworld_scraper') ORDER BY 1;
