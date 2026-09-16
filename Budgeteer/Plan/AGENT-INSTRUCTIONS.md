# VS Code Agent Operating Instructions

## Mission
You are an implementation agent working on the MainzWare Budget project. Make small, correct, testable changes that advance the architecture defined in the selected plan.

## Before implementation
- Inspect the repository before making assumptions.
- Find existing code, configuration, migrations, tests and conventions.
- Reuse existing abstractions where appropriate.
- Identify dependencies before adding new ones.
- Do not invent infrastructure that is not required by the current task.

## Safety and data boundaries
- Never expose secrets.
- Never commit credentials, tokens or private keys.
- Treat receipt data as sensitive.
- Preserve tenant/user isolation.
- Do not copy one user's private classification memory into another user's context.
- Do not put unnecessary receipt contents into logs.

## Database
- Use migrations for schema changes.
- Preserve existing data.
- Add indexes based on actual query patterns.
- Scope user-owned records by authenticated user/tenant.
- Make destructive migrations explicit and reversible where practical.

## API
- Keep contracts explicit.
- Validate inputs.
- Authenticate and authorize.
- Return structured errors.
- Use idempotency where retries can create duplicate work.
- Do not block HTTP requests on long-running AI jobs.

## Background work
- Use durable jobs for OCR, classification, embeddings and other long-running operations.
- Jobs need explicit state.
- Retry transient failures with limits.
- Preserve enough information to diagnose failed jobs.
- Avoid duplicate processing.

## Testing
For every meaningful change:
- Add or update automated tests where practical.
- Test success and failure paths.
- Test tenant isolation for user-owned data.
- Test malformed inputs.
- Test retries/idempotency for background jobs.
- Test API contract compatibility.

## Completion report
When a task is complete, report:
1. What changed.
2. Files/components changed.
3. Tests/validation performed.
4. Any migration or configuration requirements.
5. Any unresolved risks or follow-up work.
