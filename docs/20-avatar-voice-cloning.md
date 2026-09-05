# 20 - Avatar and Voice Cloning (Phase 8)

## Media identity system

`media_identities(id, person_name, person_contact, kind real_person|synthetic_persona, avatar_asset_ref, voice_model_ref, provider, consent_document_ref, consent_signed_at, permitted_uses[] (product_promo|brand_presenter|customer_reply|internal), brands_allowed[], territories, expires_at, revoked_at, revoked_reason)`.

Rules:
- **No identity, no generation.** `generate_video` / `generate_voice` refuse unless a non-revoked, non-expired identity with the matching permitted use and brand is referenced.
- **Consent is a document**, stored in the asset library, signed by the person, naming the brands, uses and duration. A verbal yes is not consent.
- **Revocation is immediate**: revoked identities are excluded from generation and all scheduled content using them is paused and re-approved.
- **Synthetic brand presenters** (no real person) are the default recommendation for scale; real-person clones only for the founder or a paid presenter with a contract.
- Every generated asset carries provenance (identity id, provider, prompt hash) and a visible or metadata disclosure where the platform requires it.

## Providers

HeyGen (API key exists in n8n, avatar video workflows built but inactive), ElevenLabs or similar for voice, OpenAI for TTS. All behind `MediaProvider` adapters; provider-specific ids stored on the identity, never in prompts.

## Pipeline hook

Content factory stages `voice` and `avatar` (doc 17) call these tools; QA checks the consent scope again at publish time because approvals can be days apart from generation.

## Open items

Whose face and voice, if anyone's; contract template; storage of the consent documents (asset library, restricted); whether a founder avatar for Bôr dé Güna is wanted at all. All in doc 31.
