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
├── live-worship/                # standalone worship product, launched from the portal
│   ├── frontend/               #   independently built React application
│   └── api/                    #   app-owned PHP API, migrations, and private page storage
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

## Database product boundaries

Product boundaries use PostgreSQL schemas (`portal.*`, `budget.*`,
`live_worship.*`, and shared `auth.users`). The portal/Budgeteer split from
`public` is live in production. Live Worship owns its schema and migrations under
`live-worship/api/`; that migration is applied by the app's own runner. See
`.github/agents/knowledgebase/db-migrations.md` for the portal schema split and
credential runbooks.

### MainzWare tenant standard and Live Worship target

The approved reusable tenant model is a shared control plane plus a
product-owned tenant resource. Live Worship's public-release target is:

```text
lw_control       tenant registry, provisioning, billing, support, audit, events
lw_master        Live Worship master catalog and revisions
lw_t_<tenant>    one schema per team, named from immutable tenant UUID
auth             shared MainzWare identities
```

Team is the user-facing term; tenant/instance is the platform term. The team
slug is a deterministic URL translation of the submitted display name, such as
`grace-community-church`; it is only a mutable URL identifier and is never a
schema name or authorization boundary. The shared slugger transliterates,
lowercases, hyphenates, enforces the length limit, and rejects reserved route
words. A collision asks the user for a more specific team name. The provisioner
creates all tenant feature structures at team creation; plan entitlements determine which
features are usable.

Mutable business rows use the four-field lifecycle contract
`activated_on`, `activated_by`, `inactivated_on`, and `inactivated_by`. A row is
current only when both inactivation fields are null. The paired-null invariant,
status history, and query/repository scoping are required; new `active` flags
are not permitted for this model.

Live Worship tenant schemas own team libraries, revisions, members, setlists,
live state, and future messaging/rotation/scheduling structures. `lw_master`
owns the master song catalog. Tenant imports are snapshots and tenant-created
songs can enter a central admin review queue without becoming master songs
automatically. Original keys and source chart revisions remain recoverable;
tenant transposition must not mutate the master catalog.

Team creation is a control-plane operation. The web API maps the current
MainzWare identity to a stable UUID actor, reserves the display-name-derived
slug, and inserts a queued provisioning job. A privileged DevOps worker creates
the tenant schema and applies tenant DDL; the web runtime does not execute
schema creation. A tenant remains unavailable until the worker marks its
provisioning state `active`.

Team joining is also a control-plane operation. Leader-issued invitations live
in `lw_control.tenant_invitations`, store only a hash of the one-time token,
expire after a bounded period, and are consumed transactionally with the
tenant-schema membership insert. Create, accept, expire, and revoke actions
write audit events. Invitation records remain outside the tenant schema so
membership decisions remain support-auditable through deprovisioning.

`lw_control.tenant_memberships` is a rebuildable discovery projection used for
team lists and switching. It is updated in the same transaction as tenant
membership mutations and rebuilt by the privileged platform migration, but it
is not an authorization source: every selected tenant context still verifies
the actor against the tenant schema's active `members` row.

Tenant deletion follows a delayed deprovisioning job. Pending tenant song
submissions are copied into durable `lw_control.catalog_review_queue` snapshots
before the tenant schema is removed, so Live Worship administrators can still
review them. The worker removes tenant schema/storage only after retention and
leaves the control-plane tenant tombstone; the URL slug is not reusable before
that cleanup completes.

Tenant context is explicit: a web slug selection resolves to an active tenant
UUID and verifies the actor in that tenant's `members` table. Web sessions and
mobile clients carry only that UUID; no request may choose a PostgreSQL schema
from user input. The UUID-based tenant runtime now owns tenant-scoped song,
catalog, setlist, and live-state requests; the older single-instance routes
remain only as a local migration seam. `/live-worship/` is the common landing,
login, Create Team, and Join Team route; it never loads tenant song data without
first selecting an active tenant. A selected or automatically resolved team is
then routed to `/live-worship/<slug>`.

The local development PTC Worship data has been copied into the first tenant at
`/live-worship/ptc-worship` by the explicit backfill command. The original
`live_worship` schema remains untouched as a recovery source during development;
the backfill does not copy legacy scan-page assets.

MainzWare support access is a separate, explicit path. An administrator starts
an audited `read_only` support session for one active tenant, bounded to 5–30
minutes and bound to the administrator's web session. Tenant context represents
that session as `support` without creating a tenant member row; the runtime
rejects every non-GET request while it is active. Session replacement, ending,
expiration, and tenant deprovisioning all terminate the support record. The
admin portal provides the launch/end controls, and the tenant UI displays the
read-only state and expiry.

Live Worship also records login and tenant-access activity in the control plane
for administrator troubleshooting. The ledger stores bounded event metadata,
actor/tenant references, outcome, authentication mode, and client kind; it does
not store passwords, bearer tokens, or raw session IDs. The admin portal exposes
the current three-plan feature matrix and recent login activity. The effective
tenant feature set is resolved server-side by `Entitlements::resolve()`, using
the current subscription plus active tenant entitlement rows. Plan-sourced rows
carry the exact plan and subscription UUID that granted them; explicit grants,
trials, and overrides remain independently expirable and auditable. `past_due`,
`canceled`, and `expired` subscriptions do not receive plan features until a
future billing grace policy explicitly changes that rule. The development
matrix remains provisional until product pricing and packaging are finalized.

Billing is deliberately provider-neutral. The target contract is a verified
subscription state with provider product/price references, idempotent
webhook/event processing, explicit grace-period rules, and server-derived
tenant entitlements. Billing providers must never become the authorization
source by themselves. Advanced OCR import is the first currently enforced
Pro-only capability; future messaging, rotations, scheduling, and push routes
will use the same entitlement boundary.

Scanned song images are temporary OCR inputs and are deleted after structured
song content is retained. Live Worship does not include cover-art storage or UI.
See `.github/agents/knowledgebase/live-worship-multitenancy.md` for the complete
decision record and remaining public-release work.

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
Live Worship applies the same rule with `LIVE_WORSHIP_DB_USER` for request and
worker runtime access and `LIVE_WORSHIP_MIGRATOR_DB_USER` for provisioning and
migrations. Its migrator connection fails closed when a runtime user is set
without an explicit migrator user; there is no production fallback to the
runtime role. The Live Worship admin API is a platform authorization surface,
but it still uses the restricted runtime DB connection and cannot perform DDL.

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
| `public.*` DB | `portal.*` / `budget.*` schemas; `live_worship.*` app schema | portal/budget split done; Live Worship added |

Phase 4 (the two deploy-touching renames) is complete pending a Security review.
Phase 7 (DB schemas) is complete, including its required Security review, per
the gatekeeping rules in `.github/agents/director.agent.md` (second pass,
2026-09-16 — see `.github/agents/knowledgebase/db-migrations.md`). It ran as an attended production window, gated behind an arming row so no deploy could apply it
unattended; see the runbook in `.github/agents/knowledgebase/db-migrations.md`.

## See also

- `.github/agents/knowledgebase/` — how specific systems (migrations, scraper
  schedule) actually work.
- `portal/README.md` — current stack and setup details.
