# MainzWare Portal

The MainzWare staff portal: a React SPA front end talks to a PHP REST API (documented with
OpenAPI/Swagger), which talks to PostgreSQL. It hosts the behind-authentication back-office
(properties, budget, projects) and currently also serves the public MainzWare homepage.
See [ARCHITECTURE.md](../ARCHITECTURE.md) for how this fits the wider monorepo.

See [PRODUCT_VISION.md](PRODUCT_VISION.md) for the roadmap: the budgeting feature here is
becoming **Budgeteer**, a standalone web + mobile budgeting product with receipt-photo
itemization. Every specialist agent should read that file before making architectural changes.

## Stack

- **Front end**: React (Vite) + React Router + Bootstrap (grid/utilities) + MUI (components)
- **API**: PHP REST API, spec-first via `api/openapi.yaml` (Swagger)
- **Web server**: Nginx + PHP-FPM in production; Apache remains an optional local setup
- **Database**: PostgreSQL (via `pdo_pgsql`)

## Structure

```
portal/
  frontend/            # Vite React app (dev server on :5173, proxies /api to the PHP backend)
    src/
      App.jsx           # Route definitions (React Router)
      components/       # AppShell (nav shell), ProjectCard, etc.
      pages/            # One component per route
      api/client.js     # Axios client for the PHP REST API
  api/                 # PHP REST API, served by Apache (DocumentRoot -> api/public)
    public/
      index.php         # Front controller
      .htaccess          # Rewrites all requests to index.php
    src/
      Router.php          # Matches routes from openapi.yaml
      Controllers/         # One controller per resource
      Config/Database.php  # PDO PostgreSQL connection (reads credentials from env vars)
      Data/                # Temporary static data until backed by real tables
    openapi.yaml          # Swagger/OpenAPI spec — source of truth for API routes/contracts
  research/             # Shared best-practice notes, written by the `research` agent
                          # and read by the accessibility/web-security/database/ui/api agents
```

## Local Setup

1. Frontend: `cd frontend && npm install && npm run dev` (serves on `http://localhost:5173`).
2. API: point an Apache vhost's `DocumentRoot` at `portal/api/public` (e.g. `http://localhost:8080`),
   and run `composer install` inside `api/` once dependencies are added.
3. Set environment variables for the database connection: `MAINZWORLD_DB_HOST`,
   `MAINZWORLD_DB_NAME`, `MAINZWORLD_DB_USER`, `MAINZWORLD_DB_PASSWORD`.
4. In dev, the Vite server proxies `/api/*` requests to the PHP backend (see `frontend/vite.config.js`).

## Production Deployment

The production site runs on an Ubuntu 24.04 VPS with Nginx, PHP 8.3-FPM, and PostgreSQL 16.
The public frontend is served at `https://mainzware.com`; the PHP API is served under `/api`.

### One-Time VPS Setup

Run these commands as `root` on the VPS. Do not paste the shell prompt (`root@server-862010:~#`)
with the command.

```bash
apt-get update
apt-get install -y nginx postgresql postgresql-client php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd composer unzip git certbot python3-certbot-nginx rsync curl nano
systemctl enable --now nginx
systemctl enable --now php8.3-fpm
systemctl enable --now postgresql
```

Create a non-root deployment user and application directories:

```bash
adduser --disabled-password --gecos "" mainz
usermod -aG www-data mainz
mkdir -p /var/www/mainzware
```

Create the PostgreSQL application role and database. Set the role password directly in the VPS
terminal when `\password` prompts for it:

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE mainzworld_app LOGIN;
\password mainzworld_app
CREATE DATABASE mainzworld OWNER mainzworld_app;
\q
```

After migrations are applied, grant the application role access to the tables and sequences:

```bash
sudo -u postgres psql -d mainzworld -c "GRANT USAGE ON SCHEMA public TO mainzworld_app; GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO mainzworld_app; GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO mainzworld_app; ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO mainzworld_app; ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO mainzworld_app;"
```

### DNS

At the DNS provider, point both records at the VPS IP:

```text
A     @       129.121.142.227
A     www     129.121.142.227
```

Keep existing mail records (`MX`, SPF, DKIM, and DMARC) unchanged. Verify propagation before
requesting the certificate:

```bash
dig +short mainzware.com A @1.1.1.1
dig +short www.mainzware.com A @8.8.8.8
```

Both should return `129.121.142.227`.

### First Release

Build the release on the Mac, then transfer and extract it on the VPS. The archive must not
contain `api/.env`; production secrets are created only on the VPS.

The easiest supported workflow is the repository script:

```bash
cd /Users/mains/MainzWare/MainzWorld
./deploy-mainzware.sh
```

The script builds the frontend, installs production Composer dependencies, packages the release,
uploads it, preserves the server `.env` and uploaded files, validates PHP-FPM and Nginx, and
reloads the services. It defaults to `root@129.121.142.227`; override it when needed:

```bash
DEPLOY_REMOTE=mainz@129.121.142.227 ./deploy-mainzware.sh
```

### Production Environment

Create the environment file directly on the VPS:

```bash
cp /var/www/mainzware/api/.env.example /var/www/mainzware/api/.env
nano /var/www/mainzware/api/.env
```

Set real values for `MAINZWORLD_DB_*` and generate the JWT secret with:

```bash
openssl rand -base64 48
```

Then protect the file:

```bash
chown root:www-data /var/www/mainzware/api/.env
chmod 640 /var/www/mainzware/api/.env
```

PHP-FPM reads these values through its pool environment. Blank optional values must not be added
as empty `env[...]` entries because PHP-FPM rejects empty values. Restart after configuration:

```bash
systemctl restart php8.3-fpm
```

### Nginx and HTTPS

The Nginx site serves `/var/www/mainzware/frontend` and forwards `/api/` to
`/var/www/mainzware/api/public/index.php` through `/run/php/php8.3-fpm.sock`. After creating the
site link, always validate before reloading:

```bash
nginx -t
systemctl reload nginx
```

Once DNS points to the VPS, issue the certificate:

```bash
certbot --nginx -d mainzware.com -d www.mainzware.com
```

Verify renewal configuration:

```bash
certbot renew --dry-run
```

### Database Migrations

Apply migrations with `php api/bin/migrate_db.php` (safe to re-run — it skips
files already recorded in `schema_migrations`). To apply by hand instead, run
them once, in filename order, from the API directory:

```bash
cd /var/www/mainzware/api
for file in db_migrations/*.sql; do echo "Applying $file"; sudo -u postgres psql -d mainzworld -f "$file" || exit 1; done
```

Migration `006_site_wide_auth.sql` must use the existing `users` table from migration 005; it
must not attempt to rename a nonexistent `cms_users` table.

### First Application Admin

The application login is separate from the VPS `root` account, Linux `mainz` account, PostgreSQL
administrator, and `mainzworld_app` database role. Create the first Mains World admin after the
database permissions and PHP-FPM environment are configured:

```bash
cd /var/www/mainzware/api
set -a; . ./.env; set +a
read -r -s -p "Admin password: " ADMIN_PASSWORD; echo; export ADMIN_PASSWORD; php -r 'require "vendor/autoload.php"; $password = getenv("ADMIN_PASSWORD"); if (strlen($password) < 8) { fwrite(STDERR, "Password must be at least 8 characters.\n"); exit(1); } $existing = \MainzWorld\Data\Users::findByUsername("admin"); if ($existing !== null) { fwrite(STDERR, "The admin username already exists.\n"); exit(1); } $id = \MainzWorld\Data\Users::create("admin", password_hash($password, PASSWORD_BCRYPT), "admin"); echo "Created admin user ID {$id}\n";'
unset ADMIN_PASSWORD
```

Log in at `https://mainzware.com/login`. Successful login redirects to the private application
area at `/what-da-money`; `/` remains the public MainzWare homepage.

### Deployment Checks

```bash
curl -I https://mainzware.com
curl -i https://mainzware.com/api/v1/auth/me
```

The homepage should return `200 OK`. The unauthenticated API check should return `401 Unauthorized`.
Never bypass certificate errors with `curl -k` during normal verification. If DNS is still cached,
test the new VPS explicitly with `curl --resolve mainzware.com:443:129.121.142.227 -I https://mainzware.com`.

## Agents

This project is built and maintained with a set of specialist agents (see the repo-root `.github/agents/` directory):

- `research` — investigates best practices, writes notes into `research/*.md`
- `accessibility` — WCAG 2.1 compliance
- `web-security` — OWASP-aligned hardening
- `database` — PostgreSQL schema/queries
- `api` — PHP REST endpoints + OpenAPI/Swagger spec
- `ui` — React/Vite front-end implementation
- `devops` — local Apache/PHP/PostgreSQL stack setup
- `human-use` — maintainability-focused cleanup for readable, editable, easy-to-troubleshoot code
