# Mainz World

A local project hub with an API-driven architecture: a React SPA front end talks to a PHP REST
API (documented with OpenAPI/Swagger), which talks to PostgreSQL. Visiting the site shows a
landing page linking to every project in this workspace (react-crash-2021, SaleAddressMapper,
Budget Notebook, etc.).

## Stack

- **Front end**: React (Vite) + React Router + Bootstrap (grid/utilities) + MUI (components)
- **API**: PHP REST API, spec-first via `api/openapi.yaml` (Swagger)
- **Web server**: Apache (local, via Homebrew or macOS built-in)
- **Database**: PostgreSQL (via `pdo_pgsql`)

## Structure

```
MainzWorld/
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
2. API: point an Apache vhost's `DocumentRoot` at `MainzWorld/api/public` (e.g. `http://localhost:8080`),
   and run `composer install` inside `api/` once dependencies are added.
3. Set environment variables for the database connection: `MAINZWORLD_DB_HOST`,
   `MAINZWORLD_DB_NAME`, `MAINZWORLD_DB_USER`, `MAINZWORLD_DB_PASSWORD`.
4. In dev, the Vite server proxies `/api/*` requests to the PHP backend (see `frontend/vite.config.js`).

## Agents

This project is built and maintained with a set of specialist agents (see `.github/agents/`):

- `research` — investigates best practices, writes notes into `research/*.md`
- `accessibility` — WCAG 2.1 compliance
- `web-security` — OWASP-aligned hardening
- `database` — PostgreSQL schema/queries
- `api` — PHP REST endpoints + OpenAPI/Swagger spec
- `ui` — React/Vite front-end implementation
- `devops` — local Apache/PHP/PostgreSQL stack setup
