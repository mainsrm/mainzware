# MainzWare Architecture & Development Philosophy

MainzWare is the company; this repository is the company's monorepo. Each
top-level folder is one product, asset library, or shared service in the
MainzWare ecosystem, with a clear boundary and a clear audience (public vs.
behind authentication).

## Folder types

- **Products** — deployable apps (`portal`, `budgeteer`, the public homepage).
- **Assets** — non-code company property (`brand`).
- **Shared services** — used by products but not user-facing (`services/`).
- **Archive** — frozen legacy, kept for reference, never deployed (`archive/`).

## Target structure

```
MainzWare/                      # company monorepo (single git repo at this root)
├── .github/                    # ONE agents folder + knowledgebase + workflows
│   └── agents/
│       └── knowledgebase/      # how THIS project's systems actually work
├── portal/                     # (currently "MainzWorld") behind-auth staff
│   │                           #   back-office, sandbox dev, privileged apps;
│   │                           #   also serves the one public homepage for now
│   ├── frontend/               #   React (Vite)
│   └── api/                    #   PHP REST API
│       └── db_migrations/
├── budgeteer/                  # new budget product: web rebuild + mobile app
├── brand/                      # (was "Logos") merch, logos, marketing assets
├── services/
│   └── property-scraper/       # (was "SaleAddressMapper") Python scraper used by portal
├── archive/                    # frozen legacy, never deployed
│   ├── budget-legacy/          #   (was "Budget" / "zzBudget")
│   └── cms-legacy/             #   (was "zzCMS")
└── ARCHITECTURE.md             # this file
```

## Audience boundary

- **Public:** the MainzWare homepage (currently served by the portal frontend).
- **Behind authentication:** the portal (staff back-office, sandbox dev,
  privileged company apps) and, in future, budgeteer.

## Stack

- **Front end:** React (Vite) + React Router + Bootstrap (grid/utilities) + MUI.
- **API:** PHP REST API, spec-first via `api/openapi.yaml`.
- **Web server:** Nginx + PHP-FPM in production; Apache for local dev.
- **Database:** PostgreSQL (via `pdo_pgsql`).

## Naming decisions

- Local folder `MainzWorld` → `portal`. This does NOT change the production
  deploy path `/var/www/mainzware`, which is intentionally decoupled from the
  local folder name (the deploy script maps one to the other).
- Same decoupling applies to the property-scraper: prod installs it at
  `/var/www/SaleAddressMapper`, independent of the repo path
  `services/property-scraper` (bridged via `MAINZWORLD_SCRAPER_DIR`). This is
  intentional, not a pending rename -- renaming the prod directory would be a
  deploy-touching change for no functional benefit.
- Environment variables keep the `MAINZWORLD_` prefix even after the folder
  rename. Env var names are internal plumbing (read by `Database.php`,
  `Jwt.php`, the vhost, prod `.env`, and the systemd `EnvironmentFile`);
  renaming them adds risk for no user-facing benefit.

## Database philosophy (future)

Mirror the product boundaries with PostgreSQL schemas instead of one flat
`public` schema (e.g. `portal.*`, `budget.*`, shared `auth.users`). This is the
highest-risk change (production data lives in `public` today) and is deferred
until the product structure is stable and budgeteer's data model is decided.

## Migration status

| From | To | Status |
|---|---|---|
| duplicate `MainzWorld/.github` | removed (root `.github` is canonical) | done |
| `Budget` (was `zzBudget`) | `archive/budget-legacy` | done |
| `zzCMS` | `archive/cms-legacy` | done |
| `Logos` | `brand` | done |
| `SaleAddressMapper` | `services/property-scraper` | done |
| `MainzWorld` | `portal` | done |
| `Python/Debt Snowball Forecaster` | portal feature (React + PHP + PostgreSQL) | done |
| `public.*` DB | `portal.*` / `budget.*` schemas | deferred (Phase 7) |

Phase 4 (the two deploy-touching renames) is complete pending a Security review.
Phase 7 (DB schemas) requires a Security review before completion, per the
gatekeeping rules in `.github/agents/director.agent.md`.

## See also

- `.github/agents/knowledgebase/` — how specific systems (migrations, scraper
  schedule) actually work.
- `portal/README.md` — current stack and setup details.
