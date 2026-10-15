# 126. Identity repair and detector extension, W2 (15 October 2026)

Branch `p3/identity-repair` from main `7fb5e71`, worktree `hq-wt/h`, test database `hq_test_h`. Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` section A3 (all ten points and its last two paragraphs), section C Stage 2, items 36, 37 and 39. Background: docs/117 (detector, flags, review, repair design §6), docs/121 §5 (the production case), docs/122 (W1 schema, quarantine, conflicts, open questions Q1 to Q3), docs/123. Nothing here is released or merged; Joy alone releases, and no production repair runs without his explicit authorization after he has read the dry run.

## Plan (committed first)

1. Migration: `customer_identity_repairs` (one row per repair batch, keyed), `customer_merge_reversals` (append-only lineage, the original `customer_merges` row untouched), and the quarantine flag `kind` CHECK widened for the new detector kinds.
2. Detector extension (item 39): messages, conversation participants, quarantined identities and open identity conflicts become findings with their reason; read-only by default, `--flag` append-only as before; suppression extended to the new row kinds before any flag exists. W1 Q3: the follow-ups screens withhold a phone the same way Customer 360 does.
3. Repair: planner (read-only), applier (locked, fingerprinted, per-split transactions, restartable), verifier (ownership and business-scoped reads before a batch is marked complete), recount (item 37). Command `hq:identity:repair` is dry-run only and prints the plan, the keys, the row counts, the lineage, the ambiguous cases and the human lists.
4. Tool `customers.quarantine.repair` (human-only, permission `hq.customers.quarantine.repair`, R3 write of HQ rows only) reached from the quarantine screen after an explicit `needs_repair` review; the only writer of a repair. Pins extended the way docs/115 and docs/117 did.
5. Tests: the production case as a fixture; dry run writes nothing; apply splits; second apply proposes nothing; lineage and audit; concurrent change refused; ambiguous row quarantined and reported; consent untouched; reads business-scoped afterwards; tool human-only, refused for agents, permission required.
6. Gate (full Pest parallel, Pint, tsc, Vite, Bangla dictionary, no em dash) and this record.
