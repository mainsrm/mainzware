# Agent Knowledgebase

Durable, factual documentation of **how this project's systems actually work** —
not troubleshooting logs, not one-off incident write-ups. If something here stops
being true (the mechanism changes), update the file; don't append a new one.

This is distinct from `.github/agents/research/*.md`, which holds general industry
best practices (WCAG, OWASP, etc.) that apply to any project. Files here are
specific to how *this* codebase is built and wired together.

Every specialist agent should check here before assuming how a system works,
and should add/update an entry here whenever they figure out a non-obvious
mechanism worth remembering.

## Index

- [`db-migrations.md`](db-migrations.md) — how schema changes are tracked and applied
- [`property-scraper-schedule.md`](property-scraper-schedule.md) — how and when the property scraper runs
