-- Phase 7, step 2 of 2: move the flat `public` schema onto product-boundary
-- schemas. REQUIRES manual/027_schema_split_prepare.sql to have run first.
--
-- Run ATTENDED and out of band -- NOT inside an unattended deploy. Take a
-- restore-verified pg_dump PLUS pg_dumpall --globals-only first (roles and
-- `ALTER ROLE ... SET` are not in a normal dump). Reverse with
-- manual/027_schema_split_revert.sql.
--
-- NOT A SECURITY BOUNDARY. These schemas are product boundaries, not tenant
-- boundaries. One role still reaches across all three, so a missing
-- `WHERE user_id = ...` is still a full cross-tenant read. Do not describe this
-- change as tenant isolation anywhere -- Row-Level Security is that mechanism
-- and it is not in scope.
--
-- Deliberately ONE file with no explicit BEGIN/COMMIT: migrate_db.php wraps each
-- file in a single transaction and ALTER TABLE ... SET SCHEMA is genuinely
-- transactional, so every table moves or none does. Splitting this across files
-- would forfeit the atomicity and allow a half-split database.
--
-- THE ONLY SUPPORTED INVOCATION IS:  php portal/api/bin/migrate_db.php
-- Do NOT run this as `psql -f`. Outside a transaction block the SET LOCAL lines
-- below degrade to a WARNING and set nothing, so the run would take
-- ACCESS EXCLUSIVE on 18 tables with no lock_timeout -- the precise production
-- stall these timeouts exist to prevent, and the warning scrolls past unnoticed.
-- If you must use psql, use `psql --single-transaction -f`.

-- This transaction takes ACCESS EXCLUSIVE on every table below. Unbounded, it
-- queues behind any long-running query and stalls the entire app while holding
-- locks. Better to abort and retry than to wedge production.
--
-- statement_timeout must exceed the worst-case sum of the lock waits
-- (18 tables x 5s = 90s), otherwise it fires first and reports a generic
-- "canceling statement due to statement timeout" instead of naming the lock.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '120s';

DO $$
DECLARE
    rec        record;
    moved      int := 0;
    stragglers text;
BEGIN
    -- Arming gate. deploy-mainzware.sh runs migrate_db.php on EVERY deploy, so
    -- without this the window between the prepare script and the attended run is
    -- a trapdoor: any routine deploy would apply the split mid-day, unattended,
    -- with no operator and no fresh pg_dump. A comment saying "attended only"
    -- is documentation; this is a control. The operator arms it by hand
    -- immediately before the run and disarms it immediately after:
    --   INSERT INTO public.schema_migrations (filename) VALUES ('manual/027_schema_split_ARM');
    --   php portal/api/bin/migrate_db.php
    --   DELETE FROM public.schema_migrations WHERE filename = 'manual/027_schema_split_ARM';
    IF NOT EXISTS (SELECT 1 FROM public.schema_migrations
                   WHERE filename = 'manual/027_schema_split_ARM') THEN
        RAISE EXCEPTION 'Migration 027 is not armed. This is an attended-only migration; see the header.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'portal')
       OR NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'budget')
       OR NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'auth') THEN
        RAISE EXCEPTION
            'Schemas portal/budget/auth do not all exist. Run manual/027_schema_split_prepare.sql as superuser first.';
    END IF;

    FOR rec IN
        SELECT * FROM (VALUES
            ('auth',   'users'),

            ('portal', 'projects'),
            ('portal', 'cms_content'),
            ('portal', 'sale_properties'),
            ('portal', 'scrape_sources'),
            ('portal', 'gis_sources'),
            ('portal', 'property_lists'),
            ('portal', 'property_list_items'),

            ('budget', 'budgets'),
            ('budget', 'budget_members'),
            ('budget', 'budget_transactions'),
            ('budget', 'budget_income'),
            ('budget', 'budget_categories'),
            ('budget', 'budget_category_mappings'),
            ('budget', 'budget_category_rules'),
            ('budget', 'receipts'),
            ('budget', 'receipt_items'),
            ('budget', 'debts')
        ) AS m(target_schema, table_name)
    LOOP
        IF EXISTS (SELECT 1 FROM pg_tables
                   WHERE schemaname = 'public' AND tablename = rec.table_name) THEN
            -- Owned sequences, indexes and constraints follow the table, and so
            -- do its ACLs -- the scraper's grants on sale_properties survive.
            EXECUTE format('ALTER TABLE public.%I SET SCHEMA %I', rec.table_name, rec.target_schema);
            moved := moved + 1;
        ELSIF NOT EXISTS (SELECT 1 FROM pg_tables
                          WHERE schemaname = rec.target_schema AND tablename = rec.table_name) THEN
            -- Not in public, not already moved: the mapping above names a table
            -- this database does not have. Abort rather than guess.
            RAISE EXCEPTION 'Expected table "%" in public or %, found it in neither.',
                rec.table_name, rec.target_schema;
        END IF;
    END LOOP;

    -- The guard that makes this migration safe to trust. Anything still sitting
    -- in public means the mapping above is out of date with the database (a
    -- table added by a later migration, or one created by hand in prod). Without
    -- this the split would half-apply and report success, leaving orphaned
    -- objects that the new search_path resolves inconsistently.
    --
    -- Deliberately NOT pg_tables: that is only relkind r/p, so it cannot see
    -- views, matviews, foreign tables, standalone sequences, functions, enum or
    -- domain types, or extensions. Every one of those would be left behind in
    -- public, still resolve (public is last on the path), and let this migration
    -- report success. None exist today -- this is a latent gap, closed now so the
    -- guard actually proves what its comment claims.
    SELECT string_agg(obj, ', ' ORDER BY obj) INTO stragglers FROM (
        -- relkind is "char", not text; without the cast `||` is ambiguous.
        SELECT c.relkind::text || ' ' || c.relname AS obj
          FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m','S','f')
           AND c.relname <> 'schema_migrations'
        UNION ALL
        SELECT 'function ' || p.proname FROM pg_proc p
          JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'
        UNION ALL
        SELECT 'type ' || t.typname FROM pg_type t
          JOIN pg_namespace n ON n.oid = t.typnamespace
         WHERE n.nspname = 'public' AND t.typtype IN ('e','d','r')
        UNION ALL
        SELECT 'extension ' || e.extname FROM pg_extension e
          JOIN pg_namespace n ON n.oid = e.extnamespace WHERE n.nspname = 'public'
    ) s;

    IF stragglers IS NOT NULL THEN
        RAISE EXCEPTION 'Unmapped object(s) left in public: %. Add them to 027 and re-run.', stragglers;
    END IF;

    RAISE NOTICE 'Schema split: moved % table(s); public now holds only schema_migrations.', moved;
END
$$;

-- public.schema_migrations intentionally does NOT move. It is the one table the
-- migration runner must find before any search_path is trustworthy, and
-- migrate_db.php references it schema-qualified for exactly this reason.
