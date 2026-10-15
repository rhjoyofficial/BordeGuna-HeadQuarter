# 125. Wave 4 small items (W5), 15 October 2026

Workstream W5 of `docs/120-wave-4-plan-2026-10-15.md`, under the binding ruling `docs/120-chatgpt-ruling-2026-10-15.txt` section B. Branch `platform/wave4-small-items` from main `5419493`, worktree `hq-wt/j`, test database `hq_test_j`. Nothing here activates anything; no production access; Joy alone releases.

## Plan (committed first)

Groups, each closed by a WIP checkpoint commit:

1. **S3 (items 1-7, 30-32, 35).** Item 1: a typed foreign key stays required for every internal result type; an `erp_order` reference may go without its row only while HQ holds no mirror of that ERP order, and is refused when a mirror exists. Items 2-7 and 30-32: verify in code, add a test wherever a ruled property is not yet pinned. Item 35: test the append-only correction and retraction semantics; pin that no callable retract tool exists.
2. **S5 (items 18-21, 34).** A separate, labelled "what I would have sent" box on the shadow review that sends nothing. Reasons stay off the corpus page and stay in the record and the audit. One vocabulary `approved|edited|rejected` for every new decision record, with a documented compatibility map for legacy `send`/`discard`; history is not rewritten. Additive `decision_records.business_id`, backfilled only from a context row that names a business; the rest stays null and is reported; a null never widens a read.
3. **S6 (items 22-26).** Reviewer independence with a visible single-person bootstrap rule; legacy columns untouched; business-wide and brand playbooks both load, labelled, brand refining and never widening, a conflict surfaced and not inferred; Support runs load playbooks read-only and gain nothing.
4. **S7 (items 27-29).** Verify fail-closed identity, single-person plain lift with evidence and audit, two-person DNC lift, and the spent-approval rule, with tests.
5. **Cost and bypass (items 40-42).** The `scheduler.gateway` row is not deleted; the post-release delete procedure is written below. Counter and audit kept when alerts dedupe; threshold 3 in 24 h; no customer content or credentials in the alert.
6. **W5-TZ.** Shop timestamps stored in UTC whatever offset the shop sends; the contribution report's Dhaka day windows converted to UTC before they reach Postgres. Tests with a `+06:00` payload and a day-boundary order.

Item 33 is design-only (not built). Item 23 is held by the existing structural test.

Gate: full Pest suite parallel on `hq_test_j`, Pint, `npx tsc --noEmit`, Vite build.
