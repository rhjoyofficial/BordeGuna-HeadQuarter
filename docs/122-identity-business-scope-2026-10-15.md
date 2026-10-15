# 122. Identity business scope, W0 + W1 (15 October 2026)

Branch `p3/identity-business-scope` from main `787b51a`, worktree `hq-wt/h`, test database `hq_test_h`. Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` sections A1, A2, A4 and item 38.

## Plan (committed first)

1. W0: `docs/121-identity-containment-2026-10-15.md` (every production path on `b96a396` that can write or re-point an identity, live or not, and containment OPTIONS for Joy) and `deploy/tools/identity-scope-readonly.sql` (SELECT only, inside `BEGIN READ ONLY ... ROLLBACK`).
2. W1 schema: one migration adding `customer_identities.business_id`, unique `customers (id, business_id)`, composite FK, unique `(business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT`, a store/business trigger, an identity conflict table; backfill from reliable evidence only, ambiguous rows quarantined.
3. W1 resolver: every lookup and link takes an explicit business; no group-wide fallback; an ownership conflict refuses and writes an auditable conflict row instead of re-pointing.
4. W1 defence in depth (A4) in every listed reader and writer, and item 38 on customer search.
5. Tests: the seven ruled tests, a structural pin, migration tests over clean, duplicate and corrupted fixtures, real Postgres for concurrency.
6. Gate: full Pest (parallel), Pint, `tsc --noEmit`, Vite build; this record completed.
