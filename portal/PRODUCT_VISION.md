# Mains World / Budgeteer — Product Vision

This file is the shared context for every specialist agent working in this repo. Read
this before making architectural decisions — it explains *why* the code is built the
way it is, not just what it does today.

## What Mains World is today

A local project hub: a React SPA talking to a PHP REST API backed by PostgreSQL. It
hosts several small tools, including a budgeting feature ("What Da Money").

## Where it's going: Budgeteer

The budgeting feature is being extracted into its own product, **Budgeteer**:

- **Web app** (this repo's React SPA) — ships first, publicly hosted.
- **Native mobile app** — ships next, talking to the *same* PHP REST API.
- **Unique feature**: scan a receipt photo and itemize every line item automatically
  (not just the total), for far more granular categorization than bank-transaction
  imports alone allow.
- **Self-hosted, not SaaS**: OCR (Tesseract) and the categorization algorithm are
  built and run by this project, not outsourced to a third-party OCR/AI API. This is
  a deliberate cost and IP decision — the categorization logic is meant to be a
  differentiator, so keep it in-house (`api/src/Support/Categorizer.php`,
  `ReceiptItemParser.php`).

## Non-negotiable architectural rule: web and mobile are synonymous

There is one API contract, not a "web API" and a "mobile API." Concretely:

- Every controller authenticates through `Auth::currentUser()`, which accepts
  **either** a PHP session cookie (web) **or** an `Authorization: Bearer <JWT>`
  header (mobile) — see `api/src/Support/Auth.php` and `api/src/Support/Jwt.php`.
- New endpoints must not assume a browser (no reliance on cookies-only auth, no
  HTML responses) and must not assume a native client either (session cookies still
  work for the web SPA). Both clients hit `/api/v1/...` and get the same JSON shape.
- When adding a feature, ask "does this work identically from a mobile HTTP client
  with just a bearer token?" If not, fix the endpoint rather than special-casing web.

## Data model already in place for this direction

- `receipts` / `receipt_items` (migration `018_receipts.sql`): a receipt is uploaded,
  OCR'd, parsed into line items, reviewed/edited, then confirmed into a
  `budget_transactions` row.
- Receipt processing is asynchronous by design (`status: pending → processing →
  processed/failed`) because OCR takes real time — this matters for hosting choices
  (background workers, not just request/response PHP).

## Release phases (for planning, not a hard schedule)

1. **Get Mains World live publicly** — cheap host, HTTPS, managed Postgres.
2. **Harden Budgeteer on web** — JWT already added; receipt itemization pipeline
   already added; categorization algorithm is actively evolving.
3. **Ship the mobile app** against the existing API (bearer-token auth already
   supports this).
4. **Scale toward a professional/public product** — this is where hosting needs
   change materially: object storage for receipt images (not local disk), a
   background job runner for OCR (not inline in the request), managed Postgres with
   backups/PITR, and horizontal API scaling since auth is now stateless-capable.

## What this means for the `devops` agent specifically

Local-machine setup (Homebrew, `php.ini`, `httpd.conf`, `postgresql.conf`) is still
in scope and still the primary hands-on responsibility. In addition, `devops` should
maintain hosting *research and recommendations* in `research/hosting.md` (written by
the `research` agent, consumed by `devops`) covering: managed Postgres options,
object storage (S3-compatible) for receipt images, background worker/queue hosting
for OCR, and horizontal scaling of the PHP API — evaluated against this phase plan,
not just "what's live today." Actually provisioning remote/production infrastructure
still requires explicit user confirmation per the agent's constraints.
