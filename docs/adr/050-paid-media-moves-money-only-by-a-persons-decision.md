# ADR-050: Paid media moves money only by a person's decision

**Status:** ACCEPTED (22 Sep 2026, ChatGPT's instruction recorded in `docs/88` A point 4, "ADR-050,
accept with these clarifications"). Proposed 21 Sep 2026 with `docs/84`. The clarifications are
section "Clarifications on acceptance" below and bind every Phase 5 change. What is built so far is
the READ side only (stream S, `docs/88-s-record.md`): no write tool, no credential, no registration,
nothing scheduled.

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

## Clarifications on acceptance (22 September 2026, `docs/88` A point 4)

Accepted as the governing architecture, with these clarifications, each binding:

1. **A human decision** is required for any action that changes advertising spend or materially
   changes live delivery.
2. **HQ never exposes a tool** for payment methods, billing credentials, legal identity (the
   Advertiser and Payer fields), deletion of advertising accounts, adding or removing people,
   changing account ownership, or changing the provider's hard spending ceiling (the ad account
   spending limit). `App\Ads\AdsHumanOnly` names them, and a test holds that no tool exists for any
   of them.
3. **Platform spending limits are ceilings HQ observes, never ceilings HQ rewrites.** HQ reads the
   account spending limit (`facts_ad_accounts.spend_cap_minor`) and never writes it.
4. **Reads are R2.**
5. **Recoverable external changes** (pause, lower budget, creative upload, prepare) stay
   approval-required, with precondition checks, an explicit proposal, authorisation, execution,
   read-back verification, reconciliation, and no silent repair.
6. **Irreversible or high-liability actions are HUMAN_ONLY** at every autonomy level.
7. **Raising a budget and activating a campaign are R5** and approval-required at minimum.
8. **Uploading a customer list is R5** with explicit per-person consent and is not part of the
   first release.
9. **The owner's external platform spending ceiling is an upper bound.** HQ never infers authority
   from it: room under the ceiling is not permission to spend.
10. **Platform credentials and account identity stay separate from HQ authorisation.** Holding the
    system-user token authorises nothing inside HQ; the gateway, the Effective Execution Context and
    the approval decide.
11. **MCP, if ever used,** is only an adapter behind the same Tool Gateway and capability contracts.
