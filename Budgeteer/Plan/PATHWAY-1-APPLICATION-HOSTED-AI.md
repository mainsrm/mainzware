# MainzWare Budget — Agent Development Plan
## Pathway 1: Application-Hosted AI

### Purpose
Build the MainzWare Budget application as a production-capable, multi-tenant budgeting platform with adaptive receipt classification. The initial deployment may run the PHP API, PostgreSQL, Redis, agent worker, OCR processing, and Ollama inference on the existing VPS. The software architecture must keep these responsibilities separated so they can be split and scaled later.

### Product outcome
Create an exceptional budgeting application that:
- Reads receipt line items individually.
- Understands abbreviated retail descriptions.
- Normalizes descriptions into understandable products.
- Classifies products into a controlled budget taxonomy.
- Assigns confidence.
- Asks the user when confidence is insufficient.
- Remembers user corrections.
- Reuses learned knowledge on future receipts.
- Keeps each user's knowledge private.
- Supports public App Store distribution.

### Non-negotiable architecture principles
1. Code defines behavior; database records knowledge.
2. Do not self-modify application source code as a learning mechanism.
3. `agent.md` is policy/instructions, not memory.
4. AI processing is asynchronous and queue-driven.
5. The API should not block waiting for long AI inference.
6. One shared agent/model infrastructure serves many users.
7. User memory is isolated by authenticated tenant/user.
8. The application owns the budget taxonomy.
9. Global knowledge must be deliberately validated before promotion.
10. Every classification should be traceable to its inputs, retrieved knowledge, model/version, confidence, and final outcome.
11. Components should be independently replaceable and scalable.
12. The current VPS is deployment infrastructure, not the architectural limit.

### Core processing flow
`Receipt Image -> OCR -> Line Items -> Normalize -> Retrieve Memory/Knowledge -> AI Classification -> Confidence -> Assign OR Clarify -> Learn -> Finalize`

### Agent states
`RECEIVED`
`OCR_PROCESSING`
`ITEM_EXTRACTION`
`CLASSIFYING`
`ASSIGNED`
`NEEDS_USER`
`WAITING_FOR_USER`
`USER_RESPONDED`
`LEARN_MEMORY`
`RETRY`
`FAILED`

### Agent capabilities
- `search_user_memory()`
- `search_global_knowledge()`
- `classify_product()`
- `request_user_clarification()`
- `save_classification()`
- `update_user_memory()`
- `finalize_receipt()`

### Classification contract
Return structured data, never rely on free-form prose.

Example:
```json
{
  "normalized_product": "6-foot HDMI cable",
  "category": "Electronics",
  "subcategory": "Cables & Accessories",
  "confidence": 0.98,
  "action": "ASSIGN"
}
```

Ambiguous example:
```json
{
  "action": "REQUEST_CLARIFICATION",
  "question": "What was EQ DISHWASH?",
  "possible_categories": ["Household", "Personal Care", "Other"]
}
```

### Initial confidence policy
These are starting values and must be empirically tuned:
- 95–100%: auto-assign
- 85–94%: assign with review option
- 70–84%: ask user
- below 70%: ask user

### Data model
Core tables/entities:
- `users`
- `budget_categories`
- `receipts`
- `receipt_items`
- `classification_results`
- `user_item_memory`
- `global_product_knowledge`
- `agent_tasks`
- `agent_events`
- `agent_clarifications`
- `user_preferences`
- `embedding_records`

### Infrastructure
Initial:
- Ubuntu VPS
- PHP API
- PostgreSQL
- Redis
- Agent worker
- Ollama
- Controlled receipt storage

Production evolution:
`Load Balancer -> API Servers -> Queue -> Worker Pool -> PostgreSQL`
`                                      -> AI Inference`

AI inference may later move to dedicated hardware without changing the mobile app's API contract.

### Queue jobs
- `receipt.ocr`
- `receipt.classify`
- `agent.clarification`
- `memory.index`
- `global.knowledge.review`
- `cleanup`

### Security
- HTTPS.
- Strong authentication and authorization.
- Every private query scoped to authenticated user/tenant.
- PostgreSQL, Redis and Ollama not publicly exposed.
- Least privilege.
- Encrypted backups stored separately.
- Minimize sensitive receipt contents in logs.
- Secrets outside source control.
- Account deletion/data-management workflow.
- Audit important agent decisions.

### Observability
Track:
- API latency/error rate
- Queue depth and oldest job age
- AI inference duration
- CPU
- RAM
- Swap
- PostgreSQL latency/connections
- OCR duration
- Clarification rate
- User correction rate
- Classification accuracy
- Storage
- Backup success

### Development phases
#### Phase 1 — Core budget
Implement authentication, users, budgets, categories, transactions, receipts and receipt items.

#### Phase 2 — Receipt extraction
Implement OCR and reliable line-item extraction.

#### Phase 3 — Classification
Implement normalization, taxonomy selection, structured model output and confidence.

#### Phase 4 — Agentic clarification
Implement durable clarification tasks and user responses.

#### Phase 5 — Memory
Implement exact memory first, then semantic/vector retrieval.

#### Phase 6 — Pilot
Use Dev's real receipts and controlled test receipts. Measure accuracy, correction rate and infrastructure behavior.

#### Phase 7 — Production hardening
Security, backups, monitoring, retries, failure recovery and performance.

#### Phase 8 — App Store readiness
Production deployment, privacy/data workflows, mobile release and operational readiness.

#### Phase 9 — Scale
Scale API, workers, database, storage and AI inference independently according to measured workload.

### VS Code agent rules
Before modifying code:
1. Inspect the repository structure.
2. Identify existing conventions.
3. Locate the relevant API, database, worker and configuration code.
4. Do not duplicate existing functionality.
5. Preserve backward compatibility unless the task explicitly changes the contract.
6. Prefer small, testable changes.

For every implementation task:
1. State the intended change internally.
2. Inspect relevant files.
3. Implement the smallest complete change.
4. Add/update tests where practical.
5. Validate migrations and configuration.
6. Check tenant isolation.
7. Check error and retry behavior.
8. Document meaningful architectural changes.

### Definition of done
A feature is not complete until:
- It works end-to-end.
- Data ownership is tenant-safe.
- Failures are handled.
- Durable tasks survive restarts where applicable.
- Tests or repeatable validation exist.
- Logging does not leak sensitive data.
- API contracts are explicit.
- Database changes have migrations.
