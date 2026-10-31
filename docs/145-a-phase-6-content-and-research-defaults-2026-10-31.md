# 145-a. Phase 6 content and research: decisions taken by default (31 October 2026)

Agent M4 of the HQ completion programme. No ChatGPT ruling covers these points yet, so each is built to the most conservative default (inert, human-approved, disabled). Post each question to the ChatGPT HQ thread; if a ruling differs, the change is small and named below.

## Q1. Where does the per-product claims policy live, and who writes it?

**Default built:** a table `content_claims_rules` (business, product line or `*`, forbidden or allowed, phrases, reason, legal reference, proof). Only a person with `hq.content.claims.manage` (owner and operator, through `*`) writes or retires a rule; no agent holds it. A rule is never edited or deleted (database triggers); a change is a retirement plus a new rule, both audited. An **allowed** claim must name its proof (lab report, certificate). The brief's own JSON policy (docs/85 C.15) still applies; `ClaimsRulebook` merges brief + table + floor, and each source can only add a forbidden phrase. A product line with no rule anywhere still fails closed.

**If ChatGPT rules otherwise:** `ClaimsRulebook::policyFor()` is the one merge point.

## Q2. Is there a floor that the owner's policy cannot remove?

**Default built:** yes, `App\Content\ClaimsFloor` (code, not configuration): unproven health or medical claims (cure, heals, medicine for, diabetes, cancer, blood pressure, immunity booster, and Bangla equivalents such as নিরাময়, ওষুধ, রোগ প্রতিরোধ) and testimonials or reviews (customer says, testimonial, গ্রাহকের মতামত, রিভিউ). Legal reference CRPA 2009 s.44. It only forbids, so it can never make a draft pass that the owner's rules would fail. It is a phrase list: it proves the listed phrases are absent, not that no claim exists; the human review stays the gate.

**Open for Joy:** the floor will block a real, consented testimonial too. Quoting a real customer is a person's act outside AI drafting until a "recorded source" design exists.

**Applied ruling 31 Oct (item 13):** the floor stays absolute for prohibited claims. A narrow testimonial exception now exists: a person records the testimonial (`content_testimonials`: the customer's words verbatim, an evidence asset of the original statement, a recorded consent asset, when consent was given, source and capture date, modalities, brands, channels and period; never deleted, never widened, withdrawal final). A draft written by a person may name it; only then, and only when consent covers the exact modality, brand, channel and date and the quote is verbatim (or declared edited), is the floor's `floor.testimonial` phrase rule waived for that draft. The health floor, the owner's rules, banned words and the number check still run on every word, quote included. Model output never carries a testimonial. An edited quote is approved only by confirming the exact final post (its digest). Publication judges the testimonial again.

## Q3. How are captions drafted "from playbooks"?

**Default built:** the calendar gives a person the brand's active playbooks (product claims, product information, communication standards, campaign information), the persona and the full list of forbidden phrases beside the caption box. AI drafting is a `CaptionDrafter` interface: **no implementation is bound**, the flag `content.ai_drafting` has no row, and Pause All AI refuses it. `FakeCaptionDrafter` is the sandbox. Whatever a drafter writes is labelled `generated` and checked exactly like a person's words, then reviewed by a person.

**Stub for later:** a model-backed `CaptionDrafter` must call through `ModelRuntime` (pause, routing, cost). Not built here, by design (no prompt work in this programme).

## Q4. Approval before any publish

**Default built:** a calendar review approves the **words** only. Publishing stays the existing R5 gateway tool `publishing.publish_post` with its own approval bound to the content digest (ADR-051), registered nowhere. The calendar never starts a publication; it only shows "published" when a publication of its draft was read back.

## Q5. The publisher connector

**Default built:** the existing `PostPublisher` contract (docs/85 C.13) is the publisher connector. Added `FakePostPublisher` (no network: keeps the request, reads back the same words) so the approval, digest, live re-check, read-back and reconcile path runs end to end on a laptop or staging. `PlatformRegistry` still ships empty; the real Facebook and Instagram drivers stay behind gate P6-G3.

## Q6. Research digests

**Default built:** `research_digests` (append-only). A digest is assembled from filed research artifacts of the same business: only `fact` claims (page, span, retrieval time) and `metric` claims (system of record) are copied in, verbatim, with citations. Inferences and recommendations are left in their artifact. A person's note is labelled as theirs, and any figure in it must appear in a cited entry (`Figures::unsupported`), otherwise the digest is refused. No search, fetch or model call happens from the digest screen; the research engine flag `research.engine` remains off.

## Q7. Who may plan, draft and review?

**Default built:** `hq.content.view` and `hq.content.plan` for brand managers (plus owner and operator); `hq.content.review` and `hq.content.claims.manage` for owner and operator only. A self-review is allowed and recorded (`self_reviewed` in the audit row), the same rule follow-ups use (docs/120 item 11). All reads and writes are business-scoped; a person limited to one business gets 404 for another's entries.

**Applied ruling 31 Oct (item 15):** the writer may approve their own draft only while they are the sole eligible reviewer for that business (active, two-factor confirmed, holding `hq.content.review`, business in scope), with a reason; the draft records `self_decided`, the reason and the time. Once a second eligible reviewer exists, independent review is required. Approving a draft never authorises publication. Testimonials are recorded and withdrawn under `hq.content.claims.manage`.
