# 109 - Customer 360: a read-only, business-scoped read model and owner page (10 October 2026)

Status: BUILT on branch `p3/s2-customer-360` (worktree `hq-wt/i`, from `main` `c12792f`). Not merged, not deployed. Production posture unchanged: no flag, no new permission, no migration, no new write path. Gate on 10 Oct: full Pest suite 1,857 passed (22,720 assertions, real Postgres `hq_test_i`), `pint --test` passed, `tsc --noEmit` clean, `vite build` ok. New test file `tests/Feature/Identity/Customer360Test.php` (10 tests); `customers/show.tsx` added to `BANGLA_SCREENS` (106 dictionary entries added).
Asked by: `docs/103-chatgpt-ruling-2026-10-10.txt` §5 (S2) and §11; summary `docs/101` §13. Gap it closes: `docs/95` §3 row V6 ("Conversations, memories, consent ledger, hand-overs, promises, follow-ups, outcomes on the page") and §5 Identity.

## 1. The ruling, as constraints

1. READ-ONLY. Rendering the page writes no row anywhere (a test snapshots the tables, like `PostureScreenTest`).
2. BUSINESS-SCOPED. A customer row belongs to exactly one business (`customers.business_id`); every section reads only rows of that customer AND that business. A person restricted (by `user_business_scopes`) away from the business gets a 404, never a 403 (the inbox and agent screens' rule: a record you may not see does not exist for you). The list is narrowed the same way.
3. NO CROSS-BRAND IDENTITY. One phone in LaLaDia and in Bionic stays two customers. The page never looks up "the same phone elsewhere", never links, counts or hints at another business's customer. No merge is made, proposed or suggested by the page.
4. NO AUTONOMOUS MERGE. The page adds no merge action. The existing human merge route is tightened (§6) so that even a person cannot merge two customers of different businesses (ruling §11: no cross-brand customer merging).
5. DISTINCT KINDS, NEVER BLENDED. Each section carries its kind as a label, in its own panel, with one sentence saying what that kind is and is not:
   - **Observed** - what a channel, a shop or a person put on the record (name, phone, email, identities seen, first/last seen, human-decided merges).
   - **Inferred / derived by HQ** - what HQ concluded or computed: how each identity was linked (resolver rule and confidence), pending merge candidates (never acted on), the stranger flag, aliases, HQ's order count and lifetime value (computed from copies), semantic memories (beliefs with a confidence).
   - **Source-of-truth orders (HQ copies)** - `facts_orders` rows, each with the system that owns it (shop store / ERP), how it reached HQ, and how old HQ's copy is (`last_event_at`, `synced_at`, `read_back_at`). The banner says the shop or the ERP is the truth.
   - **Conversations** - threads, state, channel, brand, dates; a link to the inbox thread. No message bodies on this page.
   - **Memory** - structured and episodic memories with provenance, source and dates; closed memories shown as closed. Memory is never truth (ADR-033).
   - **Consent records** - the current projection (`consent_state`) and the append-only ledger (`consent_events`): purpose, channel, state, basis, occurred vs recorded, expiry, actor type, whether evidence exists. The legacy `customers.consent` blob is shown separately and labelled legacy, not consent.
   - **Promises** - HQ keeps no promise ledger today. The section says so, and says nothing is inferred from message text. (Question for a ruling, §8.)
   - **Hand-overs** - audited `conversation.state_changed` rows on this customer's threads where the owner moved between agent and person, with the reason code and label (`HandoverReason`).
   - **Outcomes (S3 placeholder)** - an empty, clearly marked section: "waits for the canonical outcome model (S3)". No code depends on S3.

## 2. Read model

`App\Customer360\Customer360Reader::read(Customer $customer, User $viewer): array` - a final class, queries only (`select`), no events, no audit, no cache writes. It returns:

```
customer   : id, business {id,name,slug}, display name
observed   : {name, phone, email, first_seen_at, last_seen_at, identities[], merges[]}
inferred   : {identity_links[], candidates[], stranger, aliases[], orders_count, lifetime_value, beliefs[]}
orders     : {total, shown, rows[]: ref, store, owner_system, source, provenance, status, payment_status, gross, placed_at, delivered_at, cancelled_at, copy_updated_at}
conversations : null | {total, rows[]}          (null = viewer lacks hq.inbox.view)
memory        : null | {rows[]}                 (null = viewer lacks hq.inbox.view)
consent    : {current[], ledger[], legacy}
promises   : {recorded: false}
handovers     : null | {rows[]}                 (null = viewer lacks hq.inbox.view)
outcomes   : {status: 'awaiting_s3'}
can        : {inbox, manage}
```

The controller passes the canonical customer (merges followed) after the scope check on that canonical row.

## 3. Permission and leak rules

- Route unchanged: `GET /customers/{customer}`, `permission:hq.customers.view`, behind `auth`, `2fa.enabled`, `2fa.verified` like every page.
- Conversations, memory and hand-overs are inbox data: shown only to a viewer who also holds `hq.inbox.view` (the inbox's own permission); otherwise the section is `null` and the page says which permission it needs. So `brand_manager` (customers.view, no inbox.view) sees identity, orders and consent but no threads.
- Never sent to the browser: consent `actor_id`, `actor_label`, `reason`, `evidence`, `evidence_ref` (only `has_evidence`); audit `before`/`ip`/`user_agent`/`context`; any user email except a hand-over's acting colleague, and that only to an inbox viewer (the inbox shows it already); the absorbed customer's name or business on a merge row (only its id and a cross-business flag).

## 4. Page

`resources/js/pages/customers/show.tsx` rewritten as the 360 page, every string through `t()` with Bangla in `resources/js/lib/i18n/bn.json` (English digits, NFC, no em dash), and the screen added to `BANGLA_SCREENS` in `BanglaDictionaryTest`. Sections in the order of §1.5, each with a kind badge (Observed / Inferred / Source of truth / Memory / Consent record / Promise / Hand-over / Placeholder).

## 5. Tests (`tests/Feature/Identity/Customer360Test.php`)

1. Route: GET only, `auth` + both 2FA gates + `permission:hq.customers.view`; roles without it are refused.
2. Sections are present and labelled; orders carry owner system and copy age; consent shows projection and ledger.
3. Kinds are not blended: memory rows never appear under observed/inferred; beliefs (semantic) only under inferred; the legacy consent blob only under `consent.legacy`.
4. Business scope: a user restricted to LaLaDia gets 404 on a Bionic customer and does not see it in the list; an unrestricted owner sees both.
5. No cross-brand identity: one phone in LaLaDia and Bionic = two customers; the LaLaDia page carries none of the Bionic customer's orders, threads, consent or memories, and its id appears nowhere in the props.
6. Permission split: `brand_manager` gets `conversations`, `memory`, `handovers` = null.
7. Leaks: consent `actor_label`, `reason`, `evidence_ref` text never appears in the props.
8. No write: snapshot of counts/max ids of every table the page reads plus `audit_logs`, `events` (domain events), and the full rows of customers, identities, memories, consent projection, conversations and order copies, before and after a render, identical.
9. Merge guard: a merge across businesses is refused; a restricted user cannot merge customers of a business they cannot see.
10. No new write route (the existing `PostureScreenTest` allow-list stays as it is).

## 6. One tightening outside the read model

`IdentityResolver::merge()` refuses two customers of different businesses (the chokepoint, so any caller is covered), and `CustomerController::merge()` 404s a restricted user on a business outside their rows. This is the only change to a write path; it removes a capability (cross-business merge) the ruling forbids, adds none.

## 7. Not in this stream

No migration. No follow-ups, next-best-action, outcome or attribution logic (S3/S4). No consent writes (S7). No ERP customer ingestion. No change to the agent's `SupportContext`.

## 8. Questions for a ruling

1. **Promises.** No table records a promise. Should S2 later read promises from a store S4/S7 creates, or should a person-recorded "promise" note exist? Until ruled, the section states that nothing is recorded and nothing is inferred.
2. **Pre-existing cross-business merges.** If any `customer_merges` row joins two businesses (possible before §6), the page flags it. Should such rows be undone, or only flagged?
3. **Who sees inbox-derived sections.** Gated on `hq.inbox.view` here. Confirm, or name a dedicated `hq.customers.360` permission.
