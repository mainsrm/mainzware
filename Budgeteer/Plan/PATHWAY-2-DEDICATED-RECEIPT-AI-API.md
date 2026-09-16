# MainzWare Budget — Agent Development Plan
## Pathway 2: Dedicated MainzWare Receipt AI API

### Purpose
Build the MainzWare Budget application with a separately hosted, specialized receipt-classification AI service. The budget application owns users, budgets, receipts and transactions. The dedicated Receipt AI API owns product normalization, classification, semantic retrieval, model inference and model lifecycle.

### Product outcome
Create an exceptional budgeting application whose intelligence can improve independently from the application itself.

The system should:
- Understand individual receipt line items.
- Normalize cryptic retail descriptions.
- Classify products into a controlled taxonomy.
- Handle previously unseen products.
- Use user-specific memory.
- Ask for clarification when necessary.
- Learn from verified corrections.
- Improve through controlled model training/evaluation.
- Scale AI independently of the budget application.
- Provide a reusable MainzWare AI capability for future products.

### Critical definition
“Own our own AI model” means owning and operating a specialized model capability. It does not initially mean training a frontier LLM from zero.

Start from an appropriate open model architecture and specialize it for receipt/product classification. MainzWare should own:
- Training/labeling data.
- Evaluation datasets.
- Fine-tuning/training pipeline.
- Model versions.
- Model-serving API.
- Classification taxonomy.
- Deployment process.
- Performance measurements.

### Recommended intelligence stack
`Specialized Classifier + Embeddings + Retrieval + User Memory + Optional Reasoning Model + Agent`

Roles:
- Specialized classifier: fast high-volume classification.
- Embedding model: semantic product matching.
- Retrieval: product/global knowledge and prior user decisions.
- User memory: personalized classification.
- Reasoning model: difficult/ambiguous cases.
- Agent: decides which capability to use and when to ask the user.

### System architecture
`Mobile App -> MainzWare API -> AI Gateway -> Receipt AI API -> Model/Retrieval`
`                                      -> PostgreSQL/Vector Knowledge`
`                                      -> Model Registry`

The budget API must not know the internal model architecture.

### AI API contract
Example request:
```json
{
  "item": "HDMI CBL 6FT BLK",
  "user_context": {},
  "taxonomy_version": "2026.09",
  "request_id": "..."
}
```

Example response:
```json
{
  "normalized_product": "6-foot HDMI cable",
  "category": "Electronics",
  "subcategory": "Cables & Accessories",
  "confidence": 0.98,
  "action": "ASSIGN",
  "model_version": "receipt-ai-1.0"
}
```

Ambiguous response:
```json
{
  "action": "REQUEST_CLARIFICATION",
  "question": "What was EQ DISHWASH?",
  "model_version": "receipt-ai-1.0"
}
```

### Model architecture candidates
Evaluate:
1. Transformer text classifier.
2. Sentence embedding model.
3. Small instruction-tuned model.
4. Hybrid classifier + embedding + reasoning architecture.
5. Larger general LLM as fallback or teacher.

Preferred investigation:
`Fast specialized path -> difficult-case reasoning path`

### Training data
Candidate training records:
- Raw/normalized receipt text.
- Normalized product.
- Category/subcategory.
- Model prediction.
- Confidence.
- User accepted/corrected result.
- Clarification response.
- Model version.
- Verification status.

User corrections should become candidate training data only under explicit privacy and quality rules.

### Training loop
`Receipts -> Predictions -> Verified Corrections -> Labeled Dataset -> Train/Fine-tune -> Evaluate -> Promote -> Deploy -> Monitor`

Requirements:
- Separate training/validation/test datasets.
- Maintain a locked test set.
- Track provenance.
- Compare candidate against production model.
- Support rollback.
- Never allow evaluation data contamination.

### New-product strategy
A new product should not automatically require retraining.

Process:
1. Normalize receipt text.
2. Search semantic product knowledge.
3. Search user memory.
4. Run specialized classifier.
5. Use reasoning/fallback if uncertain.
6. Ask user if still unresolved.
7. Store verified result.
8. Accumulate repeated examples.
9. Retrain periodically when the dataset justifies it.

### User memory
The model is not the user's memory.

Layers:
- Model knowledge: general learned capability.
- Global product knowledge: validated shared mappings.
- User memory: private user preferences/classifications.
- Receipt context: current transaction.
- Agent state: current workflow.

User-specific decisions must remain private unless deliberately promoted.

### AI service components
Recommended:
- API gateway
- Classification API
- Model runtime
- Embedding service
- Vector store/database
- Product knowledge store
- Model registry
- Training pipeline
- Evaluation pipeline
- Dataset storage
- Monitoring
- Deployment/rollback mechanism

### Hosting
The AI service may run:
- Dedicated GPU VPS/server.
- Managed model endpoint.
- Containerized cloud GPU service.
- Self-hosted inference infrastructure.

The budget app's existing VPS should not be required to run the AI model in this pathway.

### Cost model
Account for:
- AI inference compute.
- Training/fine-tuning compute.
- GPU hosting.
- Storage.
- Network traffic.
- Monitoring.
- Model registry/artifacts.
- Engineering/ML operations.
- Dataset governance.

A managed inference service can reduce operations work but introduces recurring provider cost. Self-hosted GPU infrastructure can provide more control and potentially better economics at sustained utilization.

### Performance metrics
Track:
- Top-1 category accuracy.
- Top-3 accuracy.
- Product normalization accuracy.
- High-confidence precision.
- Coverage.
- Clarification precision.
- User correction rate.
- Accuracy on unseen products.
- Accuracy by merchant/receipt format.
- Inference latency.
- Cost per 1,000 line items.
- Model version performance.

The most important quality metric should be high-confidence precision: when the system claims confidence, it should be correct.

### Model versioning
Every prediction stores:
- Model version.
- Taxonomy version.
- Relevant knowledge version where applicable.
- Request ID.
- Timestamp.
- Final user outcome.

Models progress:
`DEVELOPMENT -> VALIDATION -> PRODUCTION`

Support:
- Rollback.
- Shadow testing.
- A/B testing when appropriate.
- Reproducible training configurations.

### Security
- Budget app authenticates users.
- AI Gateway authenticates the application service.
- Model service is not directly accessible by mobile clients.
- Minimize user-identifying information sent to AI.
- Do not expose internal databases publicly.
- Protect model artifacts and datasets.
- Minimize sensitive content in logs.
- Encrypt traffic.
- Apply least privilege.
- Separate production data from training datasets.

### Privacy
Define:
- Whether users opt into model improvement.
- What data may become training data.
- De-identification rules.
- Retention/deletion rules.
- Training-data provenance.
- Procedures for removing data when required.

### Failure behavior
- AI unavailable -> durable retry.
- Timeout -> controlled retry/fallback.
- Low confidence -> clarification.
- Invalid output -> reject/retry.
- Database failure -> preserve durable task state.
- AI degraded -> application remains usable for manual categorization.

### Development phases
#### Phase 1 — Taxonomy and contracts
Finalize categories, subcategories and the AI API schema.

#### Phase 2 — Dataset pipeline
Build collection, normalization, labeling, deduplication and provenance.

#### Phase 3 — Baseline models
Build simple classifier and embedding baseline. Establish measurable accuracy.

#### Phase 4 — Receipt AI API
Build versioned authenticated API and model-serving layer.

#### Phase 5 — Application integration
Make the budget app consume the AI API without knowing model internals.

#### Phase 6 — Memory and retrieval
Add user memory, semantic search and global product knowledge.

#### Phase 7 — Difficult-case intelligence
Add a stronger reasoning/fallback model only where needed.

#### Phase 8 — Model operations
Add registry, evaluation, deployment, monitoring and rollback.

#### Phase 9 — Continuous improvement
Use approved corrections to expand the dataset and periodically train improved models.

### VS Code agent rules
Before changing code:
1. Inspect repository structure and existing services.
2. Identify whether the task belongs to the Budget App or Receipt AI service.
3. Preserve the API boundary.
4. Never embed model-specific implementation details into the mobile application.
5. Do not mix training code with production inference code.
6. Do not place secrets in source control.
7. Do not use production user data as training data without the project's approved data-governance rules.

For model work:
1. Create reproducible datasets.
2. Record dataset/model versions.
3. Maintain train/validation/test separation.
4. Run evaluation before promotion.
5. Compare against the current production model.
6. Keep rollback available.
7. Record inference metrics.

For API work:
1. Validate request schema.
2. Authenticate service-to-service calls.
3. Return structured responses.
4. Apply timeouts.
5. Make retries safe/idempotent.
6. Return model version.
7. Never expose internal model infrastructure details unnecessarily.

### Definition of done
A model/service feature is complete when:
- The API contract is explicit and versioned.
- The model is reproducible.
- Evaluation data exists.
- Performance is measured.
- Model/version provenance is stored.
- Failure and timeout behavior is tested.
- Security boundaries are verified.
- Training and production data are properly separated.
- Rollback is possible.
- The budget app can consume the service without knowing its internal implementation.

### Long-term asset
The Receipt AI service should become a reusable MainzWare intelligence platform, not merely a helper for one budget application.

Potential future consumers:
- MainzWare Budget.
- Other MainzWare financial applications.
- Receipt/product analysis tools.
- Future personal-finance products.

The strategic asset is the combination of:
`Specialized Models + Proprietary Labeled Data + Product Knowledge + Evaluation System + API`
