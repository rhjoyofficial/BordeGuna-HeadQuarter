# 144. Meta Ads drafts (Phase 5): decision record (31 October 2026)

## Question

How does HQ prepare an ad campaign for Joy without ever switching one on, within Joy's USD 5 a day rule, Bangla-only copy and CRPA section 44?

## Default chosen

- **Capability `ads.draft.create`**, contract `AdsDraftPlatform` with one method, `createPausedDraft(accountRef, adAccountId, spec)` (`AdsPlatform::CREATE_DRAFT` of
  ADR-050). There is no activate, resume, publish, budget-change, delete or spending-limit method, so no implementation can be asked to; a source test reads
  `app/Ads/Draft` and fails on any `ACTIVE`, `ARCHIVED` or `DELETED` status word or any such method name. `AdsHumanOnly` (ADR-050 clarifications 2, 3, 6) is untouched.
- **Driver** `MetaAdsDraftPlatform` (Marketing API: campaign, ad set, ad creative, ad; each `status=PAUSED`, the constant being the only status the class ever sends),
  then it reads the status of the campaign, ad set and ad back. The tool refuses to call a draft paused unless every object reports PAUSED; otherwise it fails
  and names the objects so Joy can pause them in Ads Manager at once. `FakeAdsDraftPlatform` is the sandbox stand-in. Credential kind `meta_ads_draft_token`
  (`ads_management`), separate from the read-only `meta_ads_token`, per business.
- **The checks run in code before the platform is called** (`AdsDraftGuard`), in the approval preview, in the screen's "Run the checks", and again in the tool:
  1. **Budget**: the daily budget is at most USD 5.00 per campaign (Joy's rule, `feedback_campaign_budget_5_usd`), compared in whole minor units. A BDT
     budget is compared at `hq.ads.draft.usd_bdt_rate` (130.00, from Joy's own rate); with no rate configured a BDT draft is refused; any other currency is refused.
  2. **Bangla only** (Joy's rule): every copy field must contain Bangla script and no Latin letters except the allowed brand names (`LaLaDia`, `Bionic`,
     `Bionic Garden`, `Bor de Guna`) and web addresses; digits must be English digits.
  3. **Health and quality claims (CRPA 2009 section 44)**: copy that cures or treats, or says organic, 100 percent, chemical or preservative free, fresh,
     healthy or safe, BSTI, certified or lab tested, or boneless, is refused unless a person recorded the evidence for THAT claim (`claim_proofs`, a reference to
     the certificate or report). A cure or treat claim has no override at all. The list is Joy's own recorded risks (the five team-ad phrases, the hilsa
     "fresh" rule, the mustard BSTI and lab-test proof rule, organic changed to natural).
  The checks recognise WORDS, not meaning: a pass is "nothing a rule recognises", not "the copy is lawful". The screen says so, and a person still reads the
  draft in Ads Manager before switching it on.
- **R4 with approval**: `ads.create_draft` waits for a person's approval, whose card shows the checks. The ad account must be one of the business's own
  (`platform_accounts`), never a number from the request alone; a person limited to another business is refused.
- **Read side** (`ads.read_insights` and the others) already exists and is unchanged; insights remain the platform's count, never delivered orders.
- `ads_drafts` rows record the spec, the checks, the object ids and any partial creation, per business.
- Flag `connectors.ads_draft`, permission `hq.tools.ads.draft`.

## Unproven until a token arrives

Marketing API field names (`is_adset_budget_sharing_enabled`, `special_ad_categories`, the optimisation goal per objective, the creative `object_story_spec`)
are Meta's published ones as recalled; the first draft proves them. The objective-to-optimisation mapping is minimal (traffic and awareness); sales
objectives need the pixel and a conversion event and are a follow-up. An ad image needs an `image_hash` from an upload that HQ does not do yet.

## Questions for ChatGPT

1. Is "USD 5 a day per campaign" to be enforced against the ad account's own currency too, when it is BDT? (Default: converted at the configured rate.)
2. Should a recorded claim proof be reviewed by a second person? (Default: the approver of the draft reads it.)
