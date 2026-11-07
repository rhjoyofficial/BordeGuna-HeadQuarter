# 145-d. Phase 10 controlled autonomy: decisions taken by default (31 October 2026)

Agent M4. ADR-048 made the evaluation evidence policy a ceiling; ADR-020 the resolver; docs/89 §3.16 says "evidence, not time". This adds the **evidence ladder per tool per business** from real decisions. Defaults below; post to ChatGPT. The old WIP branches `autonomy/evidence-policy-and-producer` and `obs/autonomy-evidence-view` were checked: both are already fully contained in `hq-complete/base` (0 commits ahead), so nothing needed salvaging.

## Q1. What counts as evidence?

**Default built:** `ToolEvidenceLadder`, read-only, from HQ's own records, per tool per business, inside the threshold's window (90 days when none): agent proposals (approvals with an `agent_key`), how many people approved, rejected, let expire or cancelled, how many approved ones ran, succeeded, failed or ended uncertain. Rates are whole numbers per 1,000, floored: rejection = rejected / decided, error = (failed + uncertain) / executed. A zero denominator is "unknown", never zero, and unknown never meets a threshold. A person's own direct requests are not evidence.

## Q2. Who sets the numbers, and what ships?

**Default built:** `autonomy_ladder_thresholds`, one active row per (business, tool): level A1 to A4, minimum approved-and-succeeded count, maximum error and rejection rates per 1,000, window 7 to 365 days, reason. **No threshold ships. With none, autonomy is never granted.** Setting or retiring needs `hq.autonomy.grant` and group-wide authority (route middleware and service both check). A threshold is never edited or deleted (trigger); setting a new one retires the old one and revokes its grants. A permanently human-only tool can never have a threshold.

## Q3. The grant

**Default built:** human-only. `LadderGrants::grant()` takes a `User`; no tool, job or agent path names the class (a test reads the sources). It refuses while AI is paused, for an unregistered or human-only tool, above the threshold's level, above A4, and unless the evidence meets the active threshold **now**. The grant is an ordinary `autonomy_grants` row scoped to that business and tool, carrying `ladder_threshold_id`.

## Q4. Revocation

**Default built:** instant. One update of the grant row; the resolver reads grants fresh on every decision, so the next action is A0. Revoking needs only `hq.autonomy.revoke` and the business in scope (stopping is the safe direction), with a reason, audited.

## Q5. Does a ladder grant keep standing on its own?

**Applied ruling 31 Oct (item 17):** the answer below is superseded. A ladder grant no longer comes back by itself: the first time any governing condition fails (evidence below the threshold, Pause All AI on, pause state unreadable, threshold retired, or any other safety condition such as a level above the threshold or the tool becoming human-only), `LadderGrantLapse` lapses it persistently (status `lapsed`, when, which condition, why, one audit row). It happens at the next decision, at the moment of pausing, when the threshold is retired, and in the sweep `hq:autonomy:lapse-sweep`. Restoring the condition never reactivates it (a database trigger refuses that too); only a new grant by a person, after every condition is verified again, gives autonomy. Retiring a threshold now lapses its grants instead of revoking them.

Previous default, kept for the record:

**Default built:** no. The resolver honours a grant made through the ladder only while Pause All AI is off (an unreadable pause state counts as paused), its threshold is still active, and the evidence still meets it at that moment. Otherwise the grant is absent, which is A0. Grants not made through the ladder are unaffected (so every existing resolver test is unchanged). Pause All AI also still refuses every agent call in the policy engine, before any autonomy is consulted.

## Q6. What the screen shows

`/autonomy` (Bangla, light, dark, phone): the evidence table per tool per business (only tools with evidence or a threshold by default; "every tool" on demand), each threshold and whether it is met with the reasons, the grants with one-click revoke, and a red banner while Pause All AI overrides everything.

## Joy's part

The numbers. Nothing is grantable until he sets a threshold for a specific tool in a specific business, and even then only after real proposals have been approved and run well.
