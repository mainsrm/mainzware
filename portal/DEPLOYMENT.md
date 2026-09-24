# MainzWorld Deployment Checklist

This app is expected to be prepared for public release. A web-hosting push must include every source, config template, and build recipe needed to reproduce the running site. Generated artifacts should be produced by the deploy process unless the host cannot run build tools.

## Required In Git

- `README.md` and this deployment checklist.
- Repo-root `.github/agents/*.agent.md` project standards.
- `api/composer.json` and `api/composer.lock`.
- `api/openapi.yaml`.
- `api/db_migrations/*.sql`.
- `api/public/index.php` and `api/public/.htaccess`.
- `api/src/**` PHP source.
- `api/.env.example` with non-secret environment variable names.
- `frontend/package.json` and `frontend/package-lock.json`.
- `frontend/index.html`, `frontend/vite.config.js`, and `frontend/src/**`.
- `frontend/public/.htaccess` so Apache can serve React Router routes from a static build.
- `research/*.md` release standards and review notes.

## Required On The Host

- PHP 8.1+ with PDO PostgreSQL enabled.
- Python 3.9+ with venv support and `libgomp1` for the app-scoped Live Worship
  PaddleOCR CPU service (installed by `deploy-mainzware.sh`).
- PostgreSQL reachable by the PHP process.
- Nginx configured for both API forwarding and frontend route fallback.
- Nginx `client_max_body_size` set to at least 20M and PHP's
  `upload_max_filesize`/`post_max_size` set to at least 16M/20M. These clear both
  the 10MB receipt limit and Live Worship's 16MiB song page limit; otherwise photo
  uploads can fail before reaching the API.
- `tesseract-ocr` installed on the host (`apt install tesseract-ocr` / `brew install
  tesseract`) — required by `ReceiptOcr` for the receipt "process" step. Live Worship
  uses its own PaddleOCR virtual environment and loopback-only service; it does not
  change Tesseract or other apps' OCR behavior.
- API environment variables configured with real values from `api/.env.example`.
- Database migrations applied in order.
- Frontend built with `npm ci && npm run build`.
- API dependencies installed with `composer install --no-dev --prefer-dist --optimize-autoloader`.

### Adding a new environment variable

`deploy-mainzware.sh` preserves the host's `api/.env` across deploys, so a new
variable added to `api/.env.example` is **never** copied to production — it has
to be appended to `/var/www/mainzware/api/.env` by hand, followed by
`systemctl restart php8.3-fpm`.

This bites silently: code that falls back to a default when the variable is
missing keeps "working" while doing the wrong thing. `MAINZWORLD_SCRAPER_DIR`
was absent from prod for an unknown period, so `SaleScraper` fell back to a
repo-relative path that doesn't exist on the VPS and every scheduled scrape
quietly did nothing. After adding a variable, diff the two files:

```bash
ssh root@<host> "grep -o '^[A-Z_]*' /var/www/mainzware/api/.env | sort" > /tmp/prod-env-keys
grep -o '^[A-Z_]*' portal/api/.env.example | sort | comm -23 - /tmp/prod-env-keys
```

Anything printed is in the example but missing from production.

There is a second, easier-to-miss layer for anything `SaleScraper` needs: the
web-triggered scrape (adding a county in the admin UI) runs inside a PHP-FPM
worker, which does **not** read `.env` at all -- it only sees the pool's own
hardcoded `env[...]` lines (see `portal/README.md`'s "Production Environment"
section). `MAINZWORLD_SCRAPER_DIR` and `PLAYWRIGHT_BROWSERS_PATH` must be
added there too, or the web-triggered scrape fails instantly
(`scrape_state = 'error'`) even while `.env` and `check_env.php` both look
correct and the hourly systemd timer keeps working fine.

## Generated Artifacts

These are required in the deployed runtime but intentionally ignored by Git:

- `api/vendor/` from Composer.
- `frontend/dist/` from Vite.
- `frontend/node_modules/` for local/build-time dependencies only.
- `api/public/uploads/` user-uploaded runtime content.

If the web host cannot run Composer or Node, create a release package locally that includes `api/vendor/` and `frontend/dist/`. Do not commit those generated folders unless the deployment target specifically requires Git to be the release artifact.

## Build Commands

```bash
cd portal/api
composer install --no-dev --prefer-dist --optimize-autoloader

cd ../frontend
npm ci
npm run build
```

## Nginx Layout

Recommended same-domain layout:

- frontend document root: `portal/frontend/dist`
- API front controller: `portal/api/public/index.php` served through PHP-FPM under `/api`

The frontend calls the API with relative `/api/v1` URLs, so same-domain hosting avoids CORS complexity and keeps session cookies straightforward.

## Pre-Push Checks

```bash
cd portal/frontend && npm run build
cd ../api && php -l public/index.php && find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Before public launch, run the accessibility, web-security, database, API, UI, DevOps, and human-use review agents. High and medium findings are release blockers.
