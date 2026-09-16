# Database Migrations

## Where files live

`portal/api/db_migrations/*.sql`, numbered in run order (`001_`, `002_`, ...,
`020_`). Add new schema changes as the next-numbered file.

## How they're tracked

A `schema_migrations` table (one row per applied filename) records what's already
been run against a given database. It's created automatically the first time
`migrate_db.php` runs against a database that doesn't have it yet.

## How they're applied

```bash
php portal/api/bin/migrate_db.php
```

- Scans `db_migrations/*.sql`, sorted by filename.
- Skips any file already recorded in `schema_migrations`.
- Applies each pending file inside its own transaction; records it on success.
- Stops immediately (exit 1) on the first failure — never skips ahead leaving a
  partially-applied file.
- Safe to run repeatedly, on any environment, at any time — a clean database
  applies everything from scratch; an up-to-date one just reports
  "Already up to date."

## Where it's wired in

- **Local:** run manually after adding a migration file.
- **Deploy:** [`deploy-mainzware.sh`](../../portal/deploy-mainzware.sh) runs
  it automatically on the VPS during every deploy, right after `.env` is
  restored (it sources `.env` in a scoped subshell first, since PHP-FPM's own
  environment isn't available to that shell).

## Known gotcha: table ownership

Production's tables were originally owned by the `postgres` superuser (created
manually during initial setup), but the app connects as `mainzworld_app`.
`GRANT` cannot fix this: `ALTER TABLE` requires *ownership*, not privileges, so
any migration altering an existing table failed with `must be owner of table
...` even though the grants looked correct.

**Resolved 2026-09-16:** ownership of every table and sequence in prod's
`public` schema was transferred to `mainzworld_app`, so `migrate_db.php` can
apply migrations unattended during deploy:

```sql
DO $$
DECLARE obj record;
BEGIN
  FOR obj IN SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' LOOP
    EXECUTE format('ALTER TABLE public.%I OWNER TO mainzworld_app', obj.name);
  END LOOP;
  FOR obj IN SELECT sequencename AS name FROM pg_sequences WHERE schemaname = 'public' LOOP
    EXECUTE format('ALTER SEQUENCE public.%I OWNER TO mainzworld_app', obj.name);
  END LOOP;
END
$$;
```

Separately, `021_debts.sql` needed `GRANT REFERENCES ON users TO
mainzworld_app` (adding a foreign key to a table you don't own requires the
`REFERENCES` privilege on it).

**Accepted tradeoff:** the app's runtime credential can now `ALTER`/`DROP`
tables, not just read/write rows — a larger blast radius if it ever leaks. This
was chosen so deploys are self-sufficient; the counterweight is that
higher-risk components get their own scoped roles instead of the app
credential (see `manual/scoped_scraper_role.sql`).

Connect as the superuser on the VPS with `sudo -u postgres psql -d mainzworld`
(peer auth means `psql -U postgres` as root fails). Note the database is named
`mainzworld` even though the deploy path is `/var/www/mainzware`.

If a database already has tables from migrations that predate this tracking
system, bootstrap it by recording those filenames as already-applied (using the
actual table owner, e.g. `postgres`) instead of letting `migrate_db.php` try to
re-run them:

```sql
INSERT INTO schema_migrations (filename) VALUES ('002_create_sale_properties.sql'), (...)
ON CONFLICT (filename) DO NOTHING;
```

## Known exception: `db_migrations/manual/`

Not every schema change can run as the app role (`mainzworld_app`) — `CREATE
ROLE` and cross-role `GRANT`s need superuser, which that role deliberately
doesn't have. Those live in `db_migrations/manual/` instead, e.g.
[`scoped_scraper_role.sql`](../../portal/api/db_migrations/manual/scoped_scraper_role.sql).
`migrate_db.php` globs `db_migrations/*.sql` non-recursively, so this
subfolder is never auto-applied -- it's strictly a manual, run-by-hand-as-
superuser script. Keep these idempotent (safe to re-run), and have each script
`INSERT` its own filename into `schema_migrations` (e.g.
`'manual/scoped_scraper_role.sql'`, `ON CONFLICT (filename) DO NOTHING`) as
its last statement, so `SELECT * FROM schema_migrations` is still the one
place to check whether a given database has it -- same table, same check,
just applied by hand instead of by `migrate_db.php`.

## Known gotcha: prod's web pool does NOT read `.env` -- it has its own copy

Discovered 2026-09-16 during the Stage 7b credential rollout, the hard way:
`.env` is only ever read by CLI invocations that explicitly source it
(`migrate_db.php`, `check_env.php`, the scraper's systemd
`EnvironmentFile=`). The **php-fpm web pool** gets `MAINZWORLD_DB_*` from its
own hardcoded `env[...]` directives, set directly in
`/etc/php/8.3/fpm/pool.d/www.conf` -- independent of `.env`, and never touched
by `deploy-mainzware.sh` (which only manages `/var/www/mainzware` and its
`.env`).

**Consequence: rotating a DB credential requires updating BOTH files, in this
order:**
1. `ALTER ROLE ... WITH PASSWORD ...` in Postgres.
2. `MAINZWORLD_DB_PASSWORD=...` in `/var/www/mainzware/api/.env` (for CLI
   scripts).
3. `env[MAINZWORLD_DB_PASSWORD]=...` in
   `/etc/php/8.3/fpm/pool.d/www.conf` (for the actual running web app).
4. `systemctl restart php8.3-fpm` -- required for the pool to pick up its own
   config file, on top of the usual role-level `search_path` reason.

Updating only `.env` (step 2) looks like it worked -- `check_env.php` and a
manual `migrate_db.php` run both succeed, since they source `.env` directly --
but the live web app keeps using the pool's stale value and every DB-backed
request starts failing authentication. This caused a real, brief production
outage (`/properties`, `/auth/login` both 500ing for about three minutes)
during a routine password rotation; caught fast via the `syslog()`-based error
logging (`journalctl -t mainzware-api`) added earlier the same night, which
named the exact failure (`password authentication failed for user
"mainzworld_app"`) and file/line immediately.

**Action item, not yet done:** reconcile the pool config to source from `.env`
properly (e.g. `clear_env = no` plus removing the hardcoded `env[]` lines, so
`.env` becomes the single source of truth `db-migrations.md` and
`ARCHITECTURE.md` already describe elsewhere) rather than requiring this
two-file dance for every future rotation. Out of scope for tonight's Stage 7b
work; tracked in `security.md`.

## Stage 7b: credential split (runtime role vs. migrator role) -- DONE 2026-09-16

`manual/runtime_role.sql` creates `mainzworld_runtime`, a DML-only role
(SELECT/INSERT/UPDATE/DELETE, no ALTER/DROP/CREATE). It replaced
`mainzworld_app` as the credential the running web app connects with.
`mainzworld_app` keeps ownership of every table and is now the migrator role,
used only by `bin/migrate_db.php` (via `Database::migratorConnection()`).

**Prerequisite:** 027 must already be applied -- the script asserts this and
refuses to run otherwise, rather than failing on a confusing "schema does not
exist" error.

**Rollout order (host-first, per the project's own rule -- see the
`check_env.php`/scraper-var incident below):**

1. Fresh `pg_dump` + `pg_dumpall --globals-only`, verify it restores.
2. Run `manual/runtime_role.sql` as superuser (`--single-transaction`).
3. Immediately set a password on the new role -- the script deliberately does
   not (never hardcode a secret in a tracked file):
   `ALTER ROLE mainzworld_runtime WITH PASSWORD '<generated>';`
4. Add `MAINZWORLD_MIGRATOR_DB_USER=mainzworld_app` (+ its existing password)
   to the host's `.env` *first*, verify with `check_env.php`, and confirm
   `migrate_db.php` still reports `Already up to date.` while explicitly using
   the migrator credential -- before touching the runtime credential at all.
5. Cutover: update the credential the **php-fpm pool** actually uses --
   `env[MAINZWORLD_DB_USER]` / `env[MAINZWORLD_DB_PASSWORD]` in
   `/etc/php/8.3/fpm/pool.d/www.conf` -- to `mainzworld_runtime`'s credential.
   **`.env`'s copy of the same variables is not what the running web app
   reads** (see the gotcha above); update both for consistency, but the pool
   config is the one that matters. Then restart php-fpm -- required both for
   the pool to reload its own config and because role-level `search_path`
   only applies to new connections.
6. Rollback, if a missing grant surfaces on a code path rehearsal didn't hit:
   revert both `env[MAINZWORLD_DB_USER]`/`PASSWORD]` in `pool.d/www.conf` and
   `.env`'s copies back to `mainzworld_app`, then restart php-fpm. The script
   is purely additive (no `REVOKE` against `mainzworld_app`), so there is no
   DB-side revert needed and no companion revert script, matching
   `scoped_scraper_role.sql`'s precedent.

**What actually happened, for the record:** the rollout above is exactly what
ran, verified at every step (backup restore-tested; role creation verified
with 0 missing grants; `migrate_db.php` verified via the explicit migrator
credential; cutover verified with a real login → create budget → insert
transaction → list round trip against the live prod API, plus a direct
confirmation that `mainzworld_runtime` is refused on `ALTER`/`DROP`). Along the
way, the same-night password-rotation incident above happened -- unrelated to
this design, but discovered because of it -- and is fully resolved; both
`pool.d/www.conf` and `.env` now correctly hold `mainzworld_runtime`'s
credential for the web app, and `mainzworld_app`'s for the migrator.
   revert script, matching `scoped_scraper_role.sql`'s precedent.

Unlike 027, this script needs no arm-gate: it lives in `db_migrations/manual/`,
which `migrate_db.php` never globs, so a routine deploy cannot trigger it.

## Attended-only migrations (027, the schema split)

`027_schema_split.sql` moves 18 tables out of the flat `public` schema into
`portal` / `budget` / `auth`. It is the highest-risk migration in the repo and is
**not** safe to let a deploy apply. Three files are involved:

| File | Runs as | When |
|---|---|---|
| `manual/027_schema_split_prepare.sql` | superuser, by hand | before 027 |
| `027_schema_split.sql` | `mainzworld_app`, via `migrate_db.php` | attended window |
| `manual/027_schema_split_revert.sql` | superuser, by hand | rollback |

**The arming gate.** `deploy-mainzware.sh` runs `migrate_db.php` on every deploy
and `027` sits in the glob it scans, so a comment saying "attended only" would be
documentation rather than a control. `027` therefore refuses to run unless a
sentinel row exists, and the operator adds it by hand only for the real window:

```sql
INSERT INTO public.schema_migrations (filename) VALUES ('manual/027_schema_split_ARM');
```
```bash
php portal/api/bin/migrate_db.php
```
```sql
DELETE FROM public.schema_migrations WHERE filename = 'manual/027_schema_split_ARM';
```

Leaving the row in place re-opens the trapdoor, so disarm in the same sitting.

**Invocation matters.** Run `027` *only* via `migrate_db.php`. It relies on the
runner's transaction for both atomicity and its `SET LOCAL lock_timeout` /
`statement_timeout`. Under a bare `psql -f` there is no transaction block, so
both timeouts degrade to a warning and set nothing — taking `ACCESS EXCLUSIVE`
on 18 tables with no lock timeout. The manual scripts must be run with
`psql --single-transaction -f` for the same reason.

**Order, and why it is this order.** Run `migrate_db.php` first and require
`Already up to date.`: the prepare script puts the new schemas ahead of `public`
on the app role's `search_path`, so any *pending* migration doing an unqualified
`CREATE TABLE` would create it in `portal` instead. Then prepare, then take the
backup, then arm and run, then **restart php-fpm** — role-level `search_path`
only applies to new connections.

**Backups must include globals.** `pg_dump` does not contain roles or
`ALTER ROLE ... SET`, which is exactly what prepare changes. Take
`pg_dumpall --globals-only` alongside it and verify the dump restores before
starting.

**What `public` keeps.** Only `schema_migrations`. It stays put because it is the
one table the runner must find before any `search_path` is trustworthy, and
`migrate_db.php` references it schema-qualified for that reason.

## Local dev must match prod's role model

Local previously had no `portal/api/.env` at all, so `Database.php` fell through
to a `null` user and libpq connected as the OS superuser (`mains`) — which is why
`debts`, `receipts`, `receipt_items` and `schema_migrations` ended up owned by
`mains` while everything else was owned by a misspelled `mainsworld_app`. The
Apache vhost (`/opt/homebrew/etc/httpd/extra/httpd-vhosts.conf`) also hardcoded
`SetEnv MAINZWORLD_DB_USER "mains"`.

That divergence makes local rehearsals worthless for anything privilege-related:
a superuser who owns everything exercises none of the paths where a migration
actually fails. Local is now aligned — role renamed to `mainzworld_app`, all
tables and sequences reassigned to it, a real `.env` (gitignored, mode 600), and
the vhost pointing at the app role with a password. Keep it that way; if a
migration needs to be rehearsed, rehearse it as `mainzworld_app`, not as you.

## Known gotcha: JWT secret required for login

Unrelated to migrations directly, but discovered in the same incident: the API
issues JWT tokens on login via `Support/Jwt.php`, which requires
`MAINZWORLD_JWT_SECRET` to be set in the PHP environment (Apache `SetEnv` or the
systemd `EnvironmentFile`/`.env`). If it's missing, login doesn't just fail
gracefully — the login request fatals server-side.
