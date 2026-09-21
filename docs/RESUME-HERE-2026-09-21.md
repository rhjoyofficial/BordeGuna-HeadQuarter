# RESUME HERE: the state of the `docs/81` milestone when work was paused (21 September 2026)

**Paused on Joy's word** ("stop all agent, make a resume point for all, start when we resume") in the
middle of the `docs/81` milestone. Nothing is half-applied: every branch is committed, `main` is
consistent and pushed, and the three unfinished streams are parked at labelled checkpoints.

⛔ **Production was not touched and nothing was switched on.** Release `3314c54`, `ai.runtime` off,
all three agents paused at A0, zero autonomy grants, Meta inbound off, no outbound provider, no
shadow period.

## 1. Read these, in this order, before doing anything

1. `docs/68` - the whole project story, kept current.
2. `docs/80` - the previous milestone (Effective Execution Context, briefing evidence bundle,
   autonomy evidence view, release state) and its section D, the merge record.
3. `docs/81` - **the milestone in progress.** Section A holds ChatGPT's 21 September instruction
   verbatim; section B the five-stream plan; section C the record of each stream that has finished.
4. This file.

## 2. The decision that governs everything now

ChatGPT's 21 September answer changed the sequence. **Gate 11 attempt 2 is no longer run on release
`3314c54`. It runs on the exact build that will become the Executive's A0 shadow build.** So:

```
fix the P0 control-plane defects  ->  full local verification  ->  Joy deploys that exact
candidate INERT  ->  verify production  ->  ONE Gate 11 attempt 2 on that build  ->  on a
complete pass only, open the seven-day A0 shadow with a mandatory --by <real human>
```

It is still **Gate 11**, not Gate 12. Attempt 1's provider-failure evidence stays untouched. No
prompt or safety semantics may be tuned to improve a gate result.

## 3. Where `main` stands

`main` = the merge of streams E and F on top of the `docs/80` milestone, pushed to
`github-personal:rhjoyofficial/BordeGuna-HeadQuarter`.

**Verified on the merged tree at the pause, not recalled:** full Pest suite **1046 passed, 0 failed**
(9,368 assertions, 231 s), `pint --test` passed, `tsc --noEmit` clean, `vite build` succeeded.
Local verification passed; GitHub Actions validation deferred until the 1 October reset. That 1046 is
the number the next merge must not reduce.

| Stream | Branch | State |
| --- | --- | --- |
| E. Disabled users cannot authenticate; business scope never stale; `--by` mandatory on production | `fix/auth-scope-and-shadow-opener` | **MERGED** |
| F. Approval records the asker apart from the enabler; stable tie ordering; execution context on the run page | `fix/approval-requester-ordering-and-run-context` | **MERGED** |
| G. One rollback invariant; deploy verify-only | `deploy/rollback-invariant-and-verify-only` | **PAUSED at a checkpoint** |
| H. Versioned autonomy evidence policy and the evidence producer | `autonomy/evidence-policy-and-producer` | **PAUSED at a checkpoint** |
| I. Phase 4 ERP read foundation | `p4/erp-read-foundation` + ERP `feature/hq-api-order-reads` | **PAUSED after the plan only** |

## 4. Exactly where each paused stream stopped, and what it still owes

Each stream has its own git worktree, its own Postgres test database, and its own agent brief in
`docs/81` section B. **Run that worktree's tests only as `DB_DATABASE=hq_test_<letter> php artisan
test`**, never against `hq_test` or `hq`.

### Stream G, `H:/WORK/BorDeGuna/hq-wt/g`, database `hq_test_g`
Committed: the plan (`docs/81` C.3) and one **labelled WIP checkpoint** holding `deploy/backup-first-lib.sh`
and `deploy/verify-only.sh` (both new) and edits to `deploy/deploy.sh`, `deploy/rollback.sh`,
`deploy/tests/deploy-safety-test.sh`, `deploy/tests/docker-stub.sh`.
⚠ **Nothing in that checkpoint has been verified.** The deploy-safety suite has not been re-run since
those edits.
Still owed: finish the single rollback invariant (today `rollback.sh` says no migrations run while
compose hardcodes `RUN_MIGRATIONS=1`, so a rollback does run `migrate --force`); make script, compose,
entrypoint, README and reality agree; check whether `rollback.sh` is backup-first like `deploy.sh` and
make it so; finish `--verify-only` (changes nothing: prove checkout, RELEASES.md, release-state files
and the stub's docker record are byte-identical before and after, in the passing and the failing case);
extend the deploy-safety suite (base 91) and watch the new assertions fail against the unmodified
scripts; `bash -n` everything; the as-built record in `docs/81` C.3.

### Stream H, `H:/WORK/BorDeGuna/hq-wt/h`, database `hq_test_h`
Committed: the plan (`docs/81` C.4) and one **labelled WIP checkpoint** holding the policy tables
migration (`2026_09_21_100000_create_autonomy_evidence_policy_tables.php`), four models
(`AutonomyEvidencePolicy`, `AutonomyEvidenceRequirement`, `AutonomyEvidenceHardCheck`,
`AutonomyEvidencePolicyEvent`) and the start of `app/Autonomy/Evidence/`.
⚠ **Unverified:** no migration rehearsal, no producer, no resolver integration, no tests.
Still owed: the deterministic evidence producer (recounted from `evaluation_results`, with lineage,
shared with `AutonomyEvidence` so the view keeps its 13 tests and its no-write guarantee); resolver
integration so `AgentRunner::resolveAutonomy()` actually receives dimension evidence (today nothing
produces it, so a grant requiring a rate can never match); the eligibility rules, all failing closed;
the view showing the active policy or "no policy is active"; ADR-048 plus the index; the mutation
checks. ⛔ **No numeric threshold may be invented.** Any seeded policy is proposed and inactive with
NULL numbers, and a policy with an undecided required field can never be activated.

### Stream I, `H:/WORK/BorDeGuna/hq-wt/i` (database `hq_test_i`) and the ERP worktree `H:/WORK/BorDeGuna/erp-wt/hq-reads`
Committed: the plan only (`docs/81` C.5). No code on either side. The ERP worktree already has its
own `vendor` directory installed.
Still owed: the whole stream. ERP read endpoints on the existing HQ API (product, variant, current
price with its SOURCE named, stock and availability as two separate facts, customer and order
eligibility facts that the ERP actually has); HQ connector methods and typed read tools through the
gateway; contract fixtures so the two repositories cannot drift; the `docs/79` "read side as built"
section. ⛔ **No order write of any kind, on either side.**
⚠ **`H:/WORK/BorDeGuna/bordeguna-erp` (the main ERP checkout) holds another session's live
uncommitted accounting work. Never touch it.** Stream I works only in the worktree.

## 5. What is already done in this milestone, and what it changed

**Stream E (merged).** A disabled account could still sign in: `users.is_active` existed and
authentication never read it. Now one user provider answers "nobody" for a disabled account on every
path into a session (password step, every request, remember-me cookie, password reset), and a second
layer ends a live session, cycles the remember token and audits it. 11 of 15 tests were watched
failing first. The business-scope cache (60 seconds, shared store, nothing ever invalidating it) is
removed entirely; a mid-run narrowing now ends a run's remaining calls. Opening a production shadow
period now requires `--by <a real, enabled human>`, and `as_enabled` can never open one.

**Stream F (merged).** An approval parked by an agent recorded the ENABLING user as the requester, so
the asker was never recorded. Requester, enabler, acting identity and approver are now four separate
facts, with one separation-of-duties rule enforced inside the executor (not only on the screen): a
person who asked through somebody else's agent cannot also approve it, though they may withdraw it.
The single-owner behaviour is byte-identical, and the payload hash is untouched. Grouped rows tied on
a metric now have a stable secondary key, and a tie cut at the limit is reported. The resolved
execution context is shown, read-only, on the agent run page; a run from before ADR-047 says so
rather than rendering an empty panel.

## 6. What remains after the three streams finish

1. Merge G, H and I to `main` with `--no-ff`, each after its own full local verification.
2. **Run the full suite on the COMBINED tree.** In the last milestone every branch was green alone
   and the combined tree still failed one test. The combined run is the gate, not the sum of the
   branches.
3. Name the release candidate, and hand Joy the exact deploy lines (`deploy/README.md`, section
   "NOT YET APPLIED TO PRODUCTION": deploy, then one `--no-pull` deploy so the new script writes its
   first release record).
4. Post the cumulative report to the ChatGPT HQ thread, covering the 15 items of instruction point 18.
5. Gate 11 attempt 2, once Joy has restored Anthropic capacity.

## 7. Blocked on Joy, and nothing moves past it

1. **Anthropic Console: buy about $25 of credit AND raise the monthly spend limit to $50.** Both are
   needed; with credit alone the calls stay blocked until the limit resets on 1 October.
   `docs/runbooks/anthropic-billing.md` explains it in a forwardable form. Gate 11 cannot run without
   this, so the shadow week cannot begin.
2. **Deploying the candidate and launching the gate** are his to type, each once with the `!` prefix.
3. **Meta Business Verification**, the slowest item for Phase 3 going live (`docs/70`).
4. **A browser walk** of the new pages (autonomy evidence, briefings, release state, the run page's
   execution context panel, the approval screens) needs his authenticated 2FA session. Nothing from
   either milestone has been opened in a browser.

## 8. Standing constraints, easy to forget

- **CI is paused until 1 October** by his choice. The merge gate is the full local run: Pest folder by
  folder, Pint, strict TypeScript, Vite, and the deploy-safety suite where it applies. ⛔ Never call it
  "CI green"; the wording is "local verification passed; GitHub Actions validation deferred until the
  1 October reset". On 1 October, run CI on `main` and fix whatever it finds.
- The deploy-safety suite skips itself on NTFS (mode 600 cannot be represented). It was run on Git
  Bash with an uncommitted `stat` shim on PATH. **Linux parity is unproven** and may not be claimed.
- Agents stopped by a usage limit keep their work on disk. Check `git status` in the worktree, then
  resume the same agent rather than starting a new one.
- `node_modules` in each worktree is a junction to the main checkout: never run `npm install` there.
  A worktree needs its own `composer install` (a junctioned `vendor` autoloads the wrong `app/`).

## 9. Defects found and deliberately not yet fixed

Reported to ChatGPT, or found after the report, and none of them is in a stream's brief:

1. **Horizon sits on the `web` group only**, so somebody past the password step but NOT the 2FA
   challenge can open `/horizon` if they hold `hq.horizon.view`. HQ's 2FA is meant to be mandatory.
   This is the most serious of the list and should be fixed before the release candidate.
2. `ToolAvailability` caches tool kill switches for 30 seconds and `Features` caches flags for 60
   seconds in the shared store, the same family of defect as the business-scope cache that stream E
   removed: a switch turned off can stay on at the gateway for that long.
3. `ApprovalExecutor` enforces the separation rule but still does not enforce delegation narrowing, so
   a future non-controller caller could bypass it.
4. `ApprovalService::approveWithEdit` redacts the edited payload without the descriptor's redact list
   that `park()` passes.
5. The agents index page sends the enabling user's email while the run page shows no emails.
6. `resources/js/pages/agents/show.tsx` fails `prettier --check` on the base commit.
7. `docs/32`, the ADR index, still lists nothing between ADR-019 and ADR-047.
8. `phpunit.xml` declares `CACHE_STORE` twice, harmless.
