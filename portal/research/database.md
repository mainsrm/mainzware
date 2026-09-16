# Database Standards (PostgreSQL)

This app is expected to be prepared for public release. Treat database changes as production-facing unless the user explicitly says the work is a throwaway prototype.

## Current Design Assessment

The current design is acceptable for local development, but it needs stronger database-enforced guarantees before it is durable public-release storage.

Strengths:

- Most SQL access is parameterized through PDO or Python driver bindings.
- Budget sharing is normalized around `budgets` and `budget_members`.
- Controller flows usually check owner/editor/viewer permissions before writes.

Release blockers to address before production-style use:

- The migration chain must be reproducible on a fresh database.
- Budget ownership/member invariants need database enforcement.
- Category assignments and rules should move away from mutable names as relational keys.
- Imports and property sync need duplicate/idempotency protection.
- Authorization boundaries in Data classes need a consistent project rule.

## Required Standards

- Use one unique, sequential migration number per migration file.
- Track applied migrations with a `schema_migrations` table before relying on non-idempotent migrations.
- Fresh database setup must work by applying migrations from `001` to latest without manual legacy assumptions.
- Backfill comments must match actual SQL. If a migration says it repairs existing data, it must contain that repair.
- Every user-owned or budget-owned table must have a clear ownership boundary such as `budget_id`, `owner_id`, or `source_id`.
- Enforce closed-set values in PostgreSQL with `CHECK` constraints or enums. This includes user roles, budget permissions, match types, and bounded statuses.
- Prefer foreign keys over name-based relationships. Treat names, URLs, and labels as mutable display/metadata values.
- Add indexes for foreign keys and frequent API filter columns.
- Use transactions for multi-step writes where partial completion would leave inconsistent data.
- Use prepared statements with bound values for every query.
- Dynamic SQL is allowed only with fixed allowlisted identifiers and bound values.

## Budget Sharing Standards

- `budgets.owner_id` is the source of truth for the budget owner.
- `budget_members` must include an owner row that agrees with `budgets.owner_id`.
- Owner access must not be removable through share/unshare flows.
- Share updates may use an upsert on `(budget_id, user_id)`, but normal share management should only grant `viewer` or `editor`.
- Only owners can grant, update, or revoke share access unless the policy is explicitly changed.

## Import And Sync Standards

- Bank transaction imports need a per-budget duplicate strategy before public release: import fingerprint, source row hash, or explicit duplicate review.
- Property sync needs a database-enforced natural key and `INSERT ... ON CONFLICT DO UPDATE`.
- `source_id` should be the relational identity for property sources. `source_url` should be metadata or a snapshot field.

## Review Checklist

Before accepting a database change, verify:

- Migration order is deterministic and fresh-database reproducible.
- New tables have primary keys, foreign keys, ownership boundaries, constraints, and needed indexes.
- Multi-tenant reads/writes are permission checked.
- Imports/syncs have an idempotency strategy.
- Multi-step writes are transactional where consistency matters.
- SQL uses prepared statements and allowlisted dynamic identifiers only.
