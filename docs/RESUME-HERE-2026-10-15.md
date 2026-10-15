# RESUME HERE (15 October 2026): wave 4 part 1 merged; PAUSED at Joy's word

Supersedes `RESUME-HERE-2026-10-11.md`. Paused at Joy's request after all running work was finished, merged and tested. Nothing is running. No agent is active.

## 1. State
- Production = `b96a396` (unchanged since 9 Oct), healthy, inert. Nothing after it is released.
- main = `357fabc` (pushed). Contains waves 1-3 plus wave 4 part 1: W3 rounding audit (docs/124), W0+W1 identity business scope (docs/121, docs/122), W4 S4 outcome follow-ups (docs/123), W5 small items + time-zone fix (docs/125).
- Combined tree gate: 2,125 Pest passed, 0 failed, 31,947 assertions; Pint, tsc, Vite clean (local, real Postgres, parallel 3). GitHub Actions run 38681310680 on `357fabc`: see §8.
- ChatGPT ruling in force: `docs/120-chatgpt-ruling-2026-10-15.txt` (verbatim), plan `docs/120-wave-4-plan-2026-10-15.md`. The report it answered: docs/119.

## 2. Ruling headlines (docs/120)
- The cross-business identity leak is a data-isolation incident and BLOCKS any release of waves 1-4 until fixed, repaired safely and verified.
- ⛔ Do NOT open the Executive shadow week until containment and scoped-read verification.
- Joy alone releases; the final report must say `READY FOR JOY'S RELEASE DECISION` or `NOT READY`.

## 3. Exact next steps, in order
1. CI (GitHub Actions) on the exact main commit; fix anything it finds.
2. **W2, Stage 2 (ruling A3, items 36, 37, 39)**: detector over messages and participants; suppression before flags; repair command + human-only Gateway tool with permission `hq.customers.quarantine.repair`, dry-run default, only after a needs_repair review, `customer_merge_reversals` lineage, idempotent, restartable, concurrency-safe, consent append-only with a human re-record list, business-less memories withheld, business-scoped recount. It must also resolve the `customer_identity_conflicts` rows and quarantined identities W1's migration creates. Branch `p3/identity-repair` from main, one worktree (e.g. hq-wt/h), test DB of that worktree.
3. Fold in the small follow-ups below (section 5) that ChatGPT rules.
4. W6 combined gates + structural searches (no unscoped identity lookup, no direct effectful-tool call, no unauthorized consent/playbook reader).
5. W7 release readiness (ruling D): consolidated release notes waves 1-4 + manifest; migration rehearsal on a production-shaped isolated copy (apply, roll back, re-apply; identity backfill and repair against seeded corruptions; note W1's down-migration refuses once conflicts exist, so the backup path must be proved); deployment rehearsal on a disposable isolated environment (no real staging exists, say so); read-only pre- and post-release checklists; final report.
6. Post the wave 4 report to the HQ ChatGPT thread (https://chatgpt.com/c/6a9baf11-c2c8-83ee-a43b-6bc7a76498c3) with the open questions in section 5. A 12K paste becomes an attachment, which works; add a short cover line.

## 4. Joy's items
- Do NOT open the Executive shadow week (ruling A1).
- Run the read-only leak count on production (docs/121 §4; SELECT-only in a READ ONLY transaction, prints counts and row ids only):
  `! scp H:/WORK/BorDeGuna/BordeGuna-HeadQuarter/deploy/tools/identity-scope-readonly.sql vps:/tmp/ && ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T db sh -c "psql -U \$POSTGRES_USER -d \$POSTGRES_DB -v ON_ERROR_STOP=1 -f -" < /tmp/identity-scope-readonly.sql'`
  (Claude's own production reads are refused by the classifier; Joy can run this or allow it.)
- Choose containment after the counts (docs/121 §3): Option 1 keep posture (enough only if no shop webhooks in the last 7 days) or Option 2 `docker compose stop scheduler` on HQ (webhooks keep being recorded as pending and replay after the fixed release; alerts, approvals sweep and retention pause). Recommended: Option 2 if webhooks are arriving.
- Unchanged: new LaLaDia + Bionic admin tokens, Hostinger shop to HQ block, Meta verification, WhatsApp SIM, second approver, ERP write token, KVM 4.

## 5. Open questions for the next ChatGPT report (from docs/122, 123, 125)
- W1 Q1: a quarantined value that is the only key fails the event closed (dead, replayable) rather than creating a provisional customer. Confirm.
- W1 Q2: `conversations` unique (channel, external_thread_ref) is still global; a cross-business collision now fails loudly. Make the index business-scoped?
- W1 Q3: follow-ups screens show `primary_phone_canonical` without withholding (outside A4's list). Fix in W2?
- W1 deviation: unique key is partial (`WHERE quarantined_at IS NULL`) and the store/business FK uses a generated `live_store_id`, so quarantined rows stay as evidence. A refused re-point refuses the MOVE, not the order.
- W4: item 11 built as ruled (brand_manager/support_agent never decide; owner/operator may decide own proposal, recorded `self_decided`). Should self-decision be refused once a second decider exists, like item 22?
- W4: completing a follow-up writes nothing to the ledger; credit only via the human-only `outcomes.attribute`. Confirm.
- W4: overdue grace 24 h is our number (config). W4: a corrected outcome hides credits on its earlier row; a person must re-credit.
- W5-TZ: shops send `Z` for orders, `+06:00` for some status events/ERP read-back; stored stamps from `+06:00` were 6 hours off and Dhaka day bounds were read as UTC. Code fixed; existing production rows are NOT repaired (needs a ruled, dry-run-first backfill).
- The W5 merge needed only the bn.json union (no clashes); no test helper change was required.

## 6. Worktrees
`hq-wt\e` p4/s4-outcome-followups (merged) · `f` platform/whole-second-bound-audit (merged) · `h` wave4/integration (pushed to main) · `j` platform/wave4-small-items (merged) · `g`, `i`, `k` older, merged. Never `git worktree remove` without removing the node_modules junction first. Local Laragon Postgres was started with pg_ctl for the tests.

## 7. Rules in force
As RESUME-HERE-2026-10-11 §7: at most three agents at once; agents commit a plan first and WIP checkpoints; merges --no-ff one at a time; full suite on the combined tree; commit trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; no em dashes.

## 8. CI on the combined commit
- Run 38681310680 on `357fabc`: PENDING at pause time (queued 04:24 UTC).
