# 145-b. Phase 8 avatar: decisions taken by default (31 October 2026)

Agent M4. docs/20 designed the consent record; docs/95 item 26 leaves the written consent policy and whose face and voice to the boss. Nothing here chooses a person. Each point below is the most conservative default; post to ChatGPT.

## Q1. What is a consent?

**Default built:** a row in `avatar_consents`: business, the name the person agreed to be shown under (no phone, no address), kind (`real_person` or `synthetic_persona`), modalities (face, voice), permitted uses, brands, end date, and for a real person **a pointer to the signed consent document in this business's asset library plus the signing date**. A verbal yes is not consent. No phone number or address is stored.

## Q2. Which uses are allowed?

**Default built:** `product_promo`, `brand_presenter`, `internal`. docs/20 also listed `customer_reply`; it is **left out**: an avatar never answers a customer. Adding it later is a code change.

## Q3. How long can consent last?

**Default built:** every consent has an end date, at most 36 months ahead. There is no open-ended consent.

## Q4. Revocation

**Default built:** immediate and final. Revoking writes the revocation under a row lock and withdraws every unfinished generation (`requested`, `generated`) in the same transaction. A database trigger refuses any later update of a revoked row and any widening of what a consent covers (uses, brands, modalities, end date, document). Rows are never deleted.

## Q5. Generation

**Default built:** an `AvatarProvider` interface (extends the existing `MediaGenerator` platform contract) with `FakeAvatarProvider`; no provider is bound. Generation is reached only through the gateway tool `avatar.generate`: **R4** (approval required), **permanently human-only**, `requesterMayDecide: false` (the person who asks may not approve), declared in `PhaseEightTools` and **registered nowhere**. Inside, `AvatarGenerations` refuses unless AI is not paused, the flag `avatar.generation` is on, the consent is valid for this business, brand, use and modality, and a provider is bound. It reads the consent under a lock before the provider call and **again after**: a revocation that landed meanwhile withdraws the result. Every refusal leaves a `refused` row and an audit line.

## Q6. Who may record, revoke, ask?

**Default built:** `hq.avatar.view`, `hq.avatar.consent.manage`, `hq.avatar.generate`, all owner and operator only. Business-scoped.

## Credentials Joy must provide before this is live

A written consent policy and the signed consent documents (uploaded to the asset library); a HeyGen API key (and, for voice, an ElevenLabs key) stored through the credentials screen; a reviewed release that registers `avatar.generate` and binds the real provider; the flag `avatar.generation`.
