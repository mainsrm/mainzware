# Security Notes (OWASP)

## Phase 7 (public -> portal/budget/auth schema split) — review 2026-09-16

Verdict: **BLOCKER on the plan as first drafted.** The schema split itself is
low risk (no views, functions, triggers or `SECURITY DEFINER` objects exist,
and all 18 table names are unique). The danger was concentrated in the
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

- **Incident 2026-09-16: exposed DB password + ~3 min prod outage during
  Stage 7b rollout.** Two separate self-caused issues in the same session,
  both fixed same-night:

  1. A diagnostic `cat -A` on prod's `.env` (checking for hidden
     whitespace/line-ending issues) printed the live `mainzworld_app` database
     password in plaintext to the terminal transcript. Treated as an actual
     secret exposure regardless of audience. **Remediation:** rotated
     immediately — new password generated, set via `ALTER ROLE`, `.env`
     updated. Confirmed the old password no longer authenticates.
  2. That rotation caused a real outage: `/properties` and `/auth/login`
     both returned 500 for about three minutes. Root cause — discovered live
     — prod's php-fpm web pool does **not** read `.env` for its DB
     credentials; it has its own hardcoded `env[MAINZWORLD_DB_PASSWORD]=...`
     directive in `/etc/php/8.3/fpm/pool.d/www.conf`, untouched by `.env`
     edits or by `deploy-mainzware.sh`. Updating `.env` alone left the live
     web pool on the old (just-invalidated) password. Diagnosed in under a
     minute via `journalctl -t mainzware-api` (the `syslog()` error logging
     added earlier the same night named the exact failure — "password
     authentication failed for user \"mainzworld_app\"" — with file/line).
     Fixed by syncing the pool config to the new password and restarting
     php-fpm; verified recovery immediately (`/properties`, `/projects`,
     `/auth/login` all correct).

  See `db-migrations.md`'s new "prod's web pool does NOT read `.env`" gotcha
  for the full mechanism and the corrected rollout order. **Follow-up, not yet
  done:** reconcile the pool config to actually source from `.env` (e.g.
  `clear_env = no`, drop the hardcoded `env[]` lines) so a future credential
  rotation is one change, not two files that can silently diverge.

- **Stage 7b (credential split) — reviewed 2026-09-16, APPLIED TO PROD same
  night.** New `manual/runtime_role.sql` creates `mainzworld_runtime`
  (DML-only: SELECT/INSERT/UPDATE/DELETE, no ALTER/DROP/CREATE) so the
  running web app no longer connects as the table-owning `mainzworld_app`.
  `Database.php` gained `migratorConnection()` (falls back to the runtime
  credential when `MAINZWORLD_MIGRATOR_DB_USER` is unset, same idiom as the
  scraper's scoped credential); `migrate_db.php` now uses it.

  Security review found no blockers, three should-fix items (all applied):
  the script never set a password for the new role (documented, not
  hardcoded — a role with no password cannot authenticate); the non-table
  completeness guard only checked `pg_tables`/`pg_sequences`, missing
  views/functions/types (added, mirroring 027's straggler-guard fix); and the
  script assumed 027 had already run without asserting it (added an explicit
  `RAISE EXCEPTION` guard rather than a confusing raw Postgres error).

  **Rehearsal caught a real bug in the fix itself:** the new completeness
  guard's `typtype = 'c'` check flagged all 18 tables as "non-table objects"
  — PostgreSQL implicitly creates a composite row type for every table
  (`typrelid` pointing at the table), which isn't a standalone type needing
  its own grant. Fixed by joining to `pg_class` and requiring `relkind = 'c'`
  (a genuine standalone composite), confirmed against the live catalog, then
  re-verified idempotent (two clean runs, 0 missing table/sequence grants).

  Fully rehearsed locally end-to-end, including an actual authenticated
  request through the live API (login → create budget → insert transaction →
  list) with the app pointed at `mainzworld_runtime`, not just direct SQL.
  **Applied to production the same night**, after: a fresh backup (restore
  verified), deploying the code change, creating the role, provisioning the
  migrator credential, and confirming `migrate_db.php` worked via it. Cutover
  verified on prod itself with the identical authenticated round-trip test
  (throwaway user + budget + transaction, immediately cleaned up), plus direct
  confirmation that `mainzworld_runtime` is refused on `ALTER`/`DROP`. See the
  incident entry above for what went wrong along the way (unrelated to the
  role's design, but real) and `db-migrations.md` for the corrected runbook
  this rollout ended up following.
- **Unidentified key `access@hal` in prod's `root` `authorized_keys`. RESOLVED
  2026-09-16 — identified, not removed.** Investigated after a new operator
  key was added (the previous one became unusable: lost passphrase). Evidence:
  (1) the key's comment matches this box's own original hostname
  (`hal-server-862010`, seen in `journalctl` before it was renamed to
  `server-862010.mainzware.com`); (2) `journalctl` (fingerprint
  `SHA256:KPMGlQ15XL4JpyxXmsIMjKG9unF84VKE34zhIbFG700`) shows continuous use
  from the very first boot record through two days before this investigation;
  (3) the source IP resolves to Newfold Digital, Inc. — Bluehost's parent
  company. Confirmed with the account owner: this VPS is managed through
  Bluehost, so this is Bluehost's own management/console access, not a
  third-party or forgotten key. **Left in place** — it is a legitimate
  recovery path independent of any operator-held key, and removing it would
  forfeit that safety net for no security benefit.
- **`ReceiptItems::delete()` deletes by `id` with no budget/user scoping.
  RESOLVED 2026-09-16.** OWASP A01, broken access control. The one wired
  route (`BudgetController::deleteReceiptItem`) already checked
  `Receipts::find()` + `belongsToReceipt()` before calling it, so it was not
  exploitable through the current API today — but the safety lived entirely in
  caller discipline, not the method, so any future caller skipping that check
  would have been an instant vulnerability. `update()` had the identical gap.
  Fixed by adding `receipt_id` to both methods' signature and `WHERE` clause,
  so authorization is enforced at the Data layer regardless of caller. The
  controller's existing `belongsToReceipt()` check is kept for the 404 message;
  the SQL scoping is the actual backstop now.
- The scraper's `MAINZWORLD_SCRAPER_DB_USER` path falls back to the full app
  credential when unset — a missing variable silently *de-hardens* the system.
  `check_env.php` reports it as a notice; consider promoting to required once
  production has been running with the scoped role for a while.
- **Unhandled exceptions leak SQL and absolute paths. RESOLVED 2026-09-16.**
  `public/index.php` set no `display_errors`, registered no
  `set_exception_handler`, and `Router::dispatch` had no `catch`. With PDO in
  `ERRMODE_EXCEPTION`, whether a raw `PDOException` (SQL text, bound context,
  filesystem paths) reached the client was decided solely by production's
  `php.ini` — a control not in the repo and not asserted by `check_env.php`.
  Observed locally as a stack trace in an HTTP **200** body, which also meant
  clients could not distinguish success from failure. OWASP A05 / CWE-209.
  Fixed in `public/index.php`: `display_errors=0` / `log_errors=1` set *before*
  the autoload require (so an autoload fatal cannot leak either), plus a
  `set_exception_handler` and a `register_shutdown_function` for `E_ERROR`
  (which the exception handler never sees) that emit a generic JSON 500.
  Verified by reproducing the real post-split failure — pointing the app role's
  `search_path` back at `public`, i.e. exactly what an un-restarted php-fpm
  would see — and confirming the client gets `{"error":"Internal server
  error."}` with zero matches for SQL or paths, while the full exception is
  still written to the server error log.

  **Prod recon found this was actually worse than local testing showed.**
  Production already has `display_errors=Off` (no leak risk from php.ini
  itself), but its php-fpm pool has `catch_workers_output=no` and no explicit
  `error_log` ini path. A bare `error_log()` call is therefore a silent no-op in
  production — not leaked to the client, but not recorded anywhere either,
  which is worse: exactly the post-split minutes where a `relation "x" does not
  exist` error is most likely would have gone completely unlogged. Fixed by
  calling `syslog(LOG_ERR, ...)` (via `openlog()`) alongside `error_log()` in
  both the exception handler and the shutdown-function fatal-error path.
  `syslog()` bypasses the ini `error_log` chain entirely. Verified empirically
  with `logger` on the prod host that syslog writes reach `journalctl`
  immediately, so this requires no host/pool config change and no deploy script
  change. Also verified locally afterward that the original fix still works
  against Apache's log.

- **nginx lacks the baseline security headers. RESOLVED 2026-09-16.**
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` existed only in
  the local Apache dev vhost; prod's `/etc/nginx/sites-available/mainzware` had
  none. Added at the `server` block level (inherited into every `location`
  block, since none define their own `add_header`) with `always`, so error
  responses get them too — matches the local Apache config's semantics. Backed
  up the config before editing, validated with `nginx -t`, reloaded, and
  confirmed all three headers on both the static frontend and `/api/` routes.
  Not committed to the repo: this project's infra config (nginx, Apache vhost)
  lives host-side only, matching existing convention — no doc/repo file
  describes prod's nginx config today, so nothing else needed updating.

## Phase 7 migration files — review 2026-09-16 (second pass)

Reviewed `027_schema_split.sql`, `manual/027_schema_split_prepare.sql` and
`manual/027_schema_split_revert.sql`. The DDL was sound; all three blockers were
in the **operational envelope**, same as the first pass. All are now fixed and
re-verified locally.

1. **`027` would have been applied unattended by the next deploy.**
   `deploy-mainzware.sh` runs `migrate_db.php` on every deploy, and `027` sits in
   the glob. A header comment saying "attended only" is documentation, not a
   control. The dangerous interleaving is *prepare ran, then someone deployed* —
   18 × `ACCESS EXCLUSIVE` mid-day with no operator and no fresh dump. Fixed with
   an **arming row**: `027` raises unless
   `manual/027_schema_split_ARM` exists in `schema_migrations`. The operator
   inserts it immediately before the run and deletes it immediately after.
   Verified: an unarmed run is refused.
2. **`REVOKE ALL ON DATABASE ... FROM PUBLIC` could have locked prod out.**
   `CONNECT` is held implicitly via `PUBLIC` while `pg_database.datacl IS NULL`,
   which is the state a hand-provisioned database stays in until someone grants
   something. The `REVOKE` materializes the ACL with owner-only rights and every
   non-owner role loses `CONNECT` instantly. Local testing structurally could not
   surface this because local already had explicit grants. Fixed with an
   unconditional `GRANT CONNECT` to both roles immediately after the revoke.
3. **The `SET LOCAL` timeouts were a no-op under the invocation the header
   invited.** "Run attended, out of band" reads as `psql -f`, which has no
   transaction block, so both timeouts degrade to a `WARNING` and set nothing —
   producing precisely the unbounded `ACCESS EXCLUSIVE` stall they exist to
   prevent. Fixed by naming `php bin/migrate_db.php` as the only supported
   invocation, and `psql --single-transaction -f` for the manual scripts.

Also fixed: the straggler guard used `pg_tables` (relkind `r`/`p` only), so
views, matviews, foreign tables, standalone sequences, functions, enum/domain
types and extensions would have been silently left in `public` while the
migration reported success. Latent today (none exist — verified against the live
catalog), now closed against `pg_class`/`pg_proc`/`pg_type`/`pg_extension`.
The prepare script must not run while any migration is pending, because it puts
the new schemas ahead of `public` on the path and a pending unqualified
`CREATE TABLE` would land in `portal`.

Recorded: **`mainzworld_app` owns the database** (`datacl` shows
`mainzworld_app=CTc`). It therefore holds `CREATE` implicitly and the
`public`-schema revokes cannot constrain it — the runtime credential can still
`CREATE`/`DROP SCHEMA`. Reducing that is Stage 7b, not 027.
