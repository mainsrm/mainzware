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
## AI Development Architecture

AI development infrastructure is repository-level infrastructure for the entire
MainzWare monorepo.

The canonical AI development system is located exclusively under:

    .github/agents/

This includes:

- AI agent definitions (`*.agent.md`)
- agent orchestration and handoffs
- agent research
- project-specific AI knowledgebase
- agent-specific operating instructions
- AI development supporting configuration

No subproject, product, service, asset, archive, or other repository directory
may contain its own:

- `.github/agents/` directory
- agent definition files
- agent knowledgebase
- agent research hierarchy
- competing agent orchestration structure
- competing AI development team or hierarchy

AI agents operate across repository boundaries as required. They are organized
by engineering discipline and responsibility, not by the directory containing
the code being modified.

Application and service directories contain the code, documentation,
configuration, tests, assets, and other artifacts belonging to that product or
service. They do not own the MainzWare AI development infrastructure.

The repository-root `.github/agents/` directory is the single canonical location
for the MainzWare AI development team.

### AI context boundaries

- `.github/agents/*.agent.md` — roles, responsibilities, constraints, and operating behavior.
- `.github/agents/knowledgebase/` — durable, MainzWare-specific facts about how systems actually work.
- `.github/agents/research/` — general/reusable best-practice research and authoritative guidance.
- Product/service documentation — product requirements, architecture, operation, and roadmap information belonging to that product/service.
- `archive/` — frozen legacy reference unless explicitly authorized.

A product README may reference the central AI system, but must not redefine or
duplicate the AI hierarchy.
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

## Database philosophy — DONE 2026-09-16

Product boundaries are now mirrored with PostgreSQL schemas instead of one flat
`public` schema (`portal.*`, `budget.*`, shared `auth.users`). This was the
highest-risk change in the repo (production data lived in `public`) and is
now live on production, alongside the credential split described below. See
`.github/agents/knowledgebase/db-migrations.md` for the full mechanism and
`.github/agents/knowledgebase/db-migrations.md` for the two review passes.

The migration credential is split from the runtime credential. **Done
2026-09-16.** Prod's `public`/now
`portal`/`budget`/`auth` tables and sequences were owned by `mainzworld_app`;
the running web app now connects as a new `mainzworld_runtime` role
(SELECT/INSERT/UPDATE/DELETE only), while `mainzworld_app` is used solely by
`bin/migrate_db.php` via `Database::migratorConnection()`. See
`.github/agents/knowledgebase/db-migrations.md`
for the rollout, the review, and an incident encountered along the way
(unrelated to the design: a hardcoded credential in the php-fpm pool config
that isn't wired to `.env`, discovered and documented, not yet fully fixed).

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
| `public.*` DB | `portal.*` / `budget.*` schemas | done |

Phase 4 (the two deploy-touching renames) is complete pending a Security review.
Phase 7 (DB schemas) is complete, including its required Security review, per
the gatekeeping rules in `.github/agents/director.agent.md` (second pass,
2026-09-16 — see `.github/agents/knowledgebase/db-migrations.md`). It ran as an attended production window, gated behind an arming row so no deploy could apply it
unattended; see the runbook in `.github/agents/knowledgebase/db-migrations.md`.

## See also

- `.github/agents/knowledgebase/` — how specific systems (migrations, scraper
  schedule) actually work.
- `portal/README.md` — current stack and setup details.
