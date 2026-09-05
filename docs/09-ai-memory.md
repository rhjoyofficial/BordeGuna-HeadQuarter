# 09 - AI Memory

Memory is what lets the AI behave consistently with how the business and its owner actually work. It is **never** the source of transactional truth; every fact that matters is re-read from the system of record before it is acted on.

## Four memory kinds

| Kind | Holds | Store | Written by | Read by |
|---|---|---|---|---|
| Structured | preferences, thresholds, brand rules, SOP pointers, customer attributes (consent, language, preferred delivery) | Postgres tables (`owner_preferences`, `brand_guidelines`, `customers.attributes`) | humans via UI, agents via approved tools | every context assembly |
| Episodic | what happened: conversation summaries, decisions taken, actions and outcomes, edits to drafts | `customer_memory(kind=episodic)`, `decision_records`, `ai_runs` | runtime automatically | retrieval by customer/brand/agent |
| Semantic | knowledge: SOPs, product knowledge, brand voice examples, policies in prose, past campaign learnings | `knowledge_documents` + `pgvector` embeddings | humans (approved docs), weekly learning job | retrieval by similarity |
| Working | the current run's facts and tool results | in-process only | runtime | discarded after the run |

## Behavior Alignment Layer (ADR-013)

```
Model + Memory + Knowledge + Skills + Tools + Policies + Feedback + Evaluation
```
This is alignment through data the system already produces, **not training**. Inputs: structured user preferences, decision memory, SOPs, policies, approved examples, approved/rejected actions, edits, feedback, outcome evaluation, agent memory. A fine-tuning step is deliberately absent and can be attached later without changing any of these stores.

- **Memory** = the tables above.
- **Knowledge** = approved documents (SOPs, brand books, product facts) with embeddings.
- **Skills** = doc 10. **Tools** = doc 11. **Policies** = doc 12.
- **Feedback** = every human edit/approval/rejection stored as a `decision_record` (proposal, final, delta, reason).
- **Evaluation** = doc 29: replay stored decisions against current prompts weekly and report agreement.

## Customer memory rules

- Keyed to the unified `customer_id` (doc 14), never to a channel id.
- Summaries, not transcripts, are embedded; transcripts stay in `messages`.
- Marketing consent and do-not-contact flags are structured fields checked by `send_message`, never inferred from memory text.
- A customer's memory can be exported and deleted on request (one command), which also deletes embeddings.

## Retrieval

- Hybrid: Postgres full-text (`tsvector`) plus pgvector cosine, merged and reranked by recency and source weight.
- Per-tier token budgets: low 1.5k, medium 4k, high 12k tokens of retrieved context.
- Every retrieved chunk carries `source_type`, `source_id`, `as_of`; the model is instructed to prefer live tool facts over retrieved ones when they conflict, and to say so.

## Embeddings

Provider-agnostic `EmbeddingService` (OpenAI `text-embedding-3-small` as default for cost; Voyage or another provider swappable). Embedding model name and dimension are stored with each vector so a model change is a re-embed job, not a migration.

## What is deliberately not memory

Prices, stock, order status, campaign spend, ledger balances. These are looked up every time.
