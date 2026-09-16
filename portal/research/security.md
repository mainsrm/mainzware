# Security Notes (OWASP)

## Phase 7 (public -> portal/budget/auth schema split) — review 2026-09-16

Verdict: **BLOCKER on the plan as first drafted.** The schema split itself is
low risk (no views, functions, triggers or `SECURITY DEFINER` objects exist,
and all 17 table names are unique). The danger was concentrated in the
migration runner and in deploy ordering, not in the DDL.

### Blockers found (both fixed before any schema work)

1. **Shadow `schema_migrations` / silent data divergence.** `migrate_db.php`
   referenced `schema_migrations` unqualified. With `search_path =
   portal,budget,auth,public`, the deploy that runs the split looks perfect
   (the new schemas don't exist yet, so everything resolves to `public`). The
   *next* deploy resolves `portal` first, finds no tracking table, creates an
   empty one, concludes nothing has ever been applied, and replays `001`
   onward — creating empty `portal.users` and `budget.*` tables that shadow
   live data. `IF NOT EXISTS` does not save you: it checks only the target
   schema. Result is invisible live data plus new writes landing in shadow
   tables. Fixed by qualifying every reference to `public.schema_migrations`
   (runner, `020`, `manual/scoped_scraper_role.sql`).
2. **Credentials shipped before they exist.** `deploy-mainzware.sh` restores
   the host's preserved `.env` over the shipped tree, so any new variable is
   absent on first deploy — guaranteed. Precedent: `MAINZWORLD_SCRAPER_DIR`
   was missing in production and every scheduled scrape silently did nothing.
   Fixed by `api/bin/check_env.php`, a fail-loud preflight wired into the
   deploy ahead of `migrate_db.php`. Rule going forward: provision the role,
   add the env var on the host, verify it, *then* deploy code that reads it.

### Constraints the eventual migration must honour

- Keep `public` **last** on the `search_path`. Unqualified `CREATE` targets the
  **first** schema on the path, which is the real exploit direction here.
- `REVOKE CREATE ON SCHEMA public FROM PUBLIC` (and from `mainzworld_scraper`);
  `REVOKE ALL ON DATABASE mainzworld FROM PUBLIC`. Verify empirically rather
  than by server version — a database restored from a pre-PG15 dump carries the
  old permissive ACL forward. A `REVOKE` by a non-owner emits a *warning*, not
  an error, and PDO will not surface it.
- Migration `027` must be a **single file with no explicit BEGIN/COMMIT** (the
  runner supplies the transaction; `ALTER TABLE ... SET SCHEMA` is genuinely
  transactional, so all-or-nothing holds only if it stays one file).
- Add `SET LOCAL lock_timeout`/`statement_timeout` — one transaction taking
  `ACCESS EXCLUSIVE` on 17 tables will otherwise queue behind any long query
  and stall production.
- Run it **attended, out of band**, not inside an unattended deploy, with a
  restore-verified `pg_dump` **plus** `pg_dumpall --globals-only` (roles and
  `ALTER ROLE ... SET` are not in a normal dump). The reverse script must also
  delete the `027` row from `public.schema_migrations`.
- ACLs and owned sequences travel with a table across `SET SCHEMA`, so the
  scraper's existing grants persist; the load-bearing addition is
  `GRANT USAGE ON SCHEMA portal TO mainzworld_scraper`.
- If the app role is later reduced to DML, it still needs `USAGE, SELECT` on
  sequences (`SERIAL` defaults, and `lastInsertId()` emits `lastval()`), and
  `ALTER DEFAULT PRIVILEGES` must be keyed `FOR ROLE <migrator>` or future
  tables are invisible to the app.

### Recorded explicitly

**These schemas are product boundaries, not tenant boundaries.** They confer no
authorization benefit: one role still reaches across all three, so a single
missing `WHERE user_id = ...` is still a full cross-tenant read. Phase 7 must
not be described anywhere as a tenant-isolation improvement. Row-Level Security
is the mechanism for that and is not currently in scope.

## Open findings (not Phase 7)

- `ReceiptItems::delete()` deletes by `id` with no budget/user scoping (OWASP
  A01, broken access control). Callers need auditing. Pre-existing, unrelated
  to the schema split.
- The scraper's `MAINZWORLD_SCRAPER_DB_USER` path falls back to the full app
  credential when unset — a missing variable silently *de-hardens* the system.
  `check_env.php` reports it as a notice; consider promoting to required once
  production has been running with the scoped role for a while.
