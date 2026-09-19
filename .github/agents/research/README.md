# Research Notes

Populated by the `research` agent. Each file holds concise, sourced best-practice
notes for the matching specialist agent to read before making changes.

- `accessibility.md` → read by the `accessibility` agent (WCAG 2.1)
- `database.md` → read by the `database` agent (PostgreSQL)
- `ui.md` → read by the `ui` agent (front-end)
- `hosting.md` → read by the `devops` agent (production hosting, scaling toward the
  Budgeteer mobile app — see `portal/PRODUCT_VISION.md` for why)

General security research should be added as `.github/agents/research/security.md`
when there is actual general/reusable security guidance to preserve. The former security notes were project-specific security/incident material and
were intentionally not copied here.code .github/agents/knowledgebase/README.md

Run the `research` agent with a topic to populate/update these files, e.g.:

"research current WCAG 2.1 best practices for card/link layouts".