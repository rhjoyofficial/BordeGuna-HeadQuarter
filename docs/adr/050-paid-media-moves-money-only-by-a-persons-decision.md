# ADR-050: Paid media moves money only by a person's decision

**Status:** proposed (21 Sep 2026, `docs/84`, the Phase 5 design and readiness packet ordered in
`docs/83` point 4). Nothing is built: no ads tool, adapter, flag or credential exists.

## Context

Phase 5 connects HQ to ad accounts, the one place in the group where software can spend money
continuously without anybody writing a payment. `docs/16` (5 September) sketched guardrails under
which an agent would raise a budget by up to 20% or pause a campaign automatically. Three later
decisions change that ground: Joy's V.1 (8 September: every R4 and R5 action requires approval, no
value threshold); ADR-023 and ADR-048 (autonomy is earned from measured evidence, through an
owner-set policy); and `docs/60` 2.11 (moving money is permanently human). Meta itself offers a hard
outer control, the ad account spending limit, which pauses every ad in the account when reached and
which the business sets in Meta (Meta Business Help Centre, read 21 September 2026).

## Decision

1. **Reading is free, writing is gated.** Campaigns, ad sets, ads, insights and the account's
   spending limit are R2 reads. Preparing a campaign (created paused, inside a campaign spending
   limit equal to the approved experiment total), pausing and lowering a budget are R4; switching a
   campaign on and raising a budget are R5. All are approval-required under V.1, each approval bound
   to the exact payload, and every write is read back before it counts (ADR-031).
2. **Some operations are not tools.** Payment methods, bills, the ad account spending limit, ad
   accounts, portfolio people and system users, the Advertiser and Payer identity fields, special ad
   category declarations, appeals, and deletion of any ad object are permanently human
   (`permanentlyHumanOnly`), and HQ does not register a tool for them at all.
3. **The owner's ceiling is outside HQ.** HQ reads the ad account spending limit and refuses to
   prepare or switch on anything that cannot fit under what remains of it; it never writes the limit.
   Two further ceilings sit inside it: the campaign spending limit per experiment and HQ's own monthly
   budget per business.
4. **Autonomy is narrow and one-directional.** The only operation that may ever run inside guardrails
   (A3) is pausing, or lowering a budget, on an owner-set breach, and only through an ADR-048 policy
   version and a grant. Increasing spend is never earned automatically.

## Alternatives

- **`docs/16`'s value-thresholded AUTO rules now.** Rejected: they predate V.1 and would let an agent
  spend money before any evidence exists.
- **HQ manages the account spending limit** so the owner has one screen. Rejected: the one control
  that stops spend even if HQ is wrong must not be writable by HQ.
- **Deleting campaigns through HQ** for tidiness. Rejected: deletion destroys the history
  reconciliation and attribution need; people archive in Ads Manager.

## Consequences

The first Phase 5 release is slower to operate than an automated one: every change to live spend is
an approval. That is the intended cost. A future owner decision to lift switch-on above A2 needs its
own ADR. The marketing suite must include the refusals (a request to change the account limit, a
"the boss agreed" injection) as blocker cases.
