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

Production's tables are owned by the `postgres` superuser (created manually
during initial setup), but the app connects as `mainzworld_app`, which does
**not** own them. Non-idempotent migration files that `ALTER`/modify an
already-existing table will fail with `must be owner of table ...` if the app
role tries to re-run them — even though the schema is already correct.

If a database already has tables from migrations that predate this tracking
system, bootstrap it by recording those filenames as already-applied (using the
actual table owner, e.g. `postgres`) instead of letting `migrate_db.php` try to
re-run them:

```sql
INSERT INTO schema_migrations (filename) VALUES ('002_create_sale_properties.sql'), (...)
ON CONFLICT (filename) DO NOTHING;
```

## Known gotcha: JWT secret required for login

Unrelated to migrations directly, but discovered in the same incident: the API
issues JWT tokens on login via `Support/Jwt.php`, which requires
`MAINZWORLD_JWT_SECRET` to be set in the PHP environment (Apache `SetEnv` or the
systemd `EnvironmentFile`/`.env`). If it's missing, login doesn't just fail
gracefully — the login request fatals server-side.
