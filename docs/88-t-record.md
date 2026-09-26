# 88 T - Stream T record: the Phase 6 research engine and content factory, inert (22 and 26 September 2026)

Branch `p6/research-and-drafts`, worktree `hq-wt/i` (its own `vendor` and `node_modules`), test database
`hq_test_i`. Built on `origin/main` at `37a2be6` under `docs/88` A points 1, 5, 8, 20 and 21, from the
`docs/85` design. Started 22 September by a fork the session lost mid-work; resumed 26 September from its
uncommitted tree: every file read and judged, two defects fixed, the record written, everything verified.

ADR-051 is moved from proposed to **ACCEPTED with clarifications**, ChatGPT's twelve clarifications written
into it, with a table saying where each is implemented.

## What was built

Everything below exists as classes, tables and tests, and does nothing by existing. The three flags
`research.engine`, `content.drafting` and `publishing.write` have no row; the five tools are registered in
no config, registry or gateway; no agent holds one; `PlatformRegistry` holds no Phase 6 provider; no search
key and no Page token is stored; the render service is defined and not started.

**1. The research engine (`app/Research`, ADR-051 points 1, 3, 4, 7, 11).**

- `ResearchService`: a person opens a task with one question, a business, an owner-approved source list
  (hosts, `*.host` wildcards or `https://` prefixes; the approver named), a fetch budget of at most 25 and a
  search budget of at most 10. Search goes through the provider the owner chose (`content.research.provider`,
  unset: every search refuses with that reason; never inferred from which key is stored). Fetch reads one
  page only if `ResearchBoundary` allows it. Close purges every fetched body unless storage rights are
  configured (Brave's term), keeping the spans and hashes.
- `ResearchBoundary`: a URL is fetched only if it is on the task's approved list or among the task's own
  search results. A page's links, a redirect, a URL quoted in a page, another task's results or memory never
  widen a task; there is no method that adds to the list from inside a task. `AddressGuard` refuses IP
  literals, single-label and internal names, names that do not resolve, and hosts that resolve to a private,
  loopback, link-local or reserved address (a search result is third-party text; without this the engine
  would fetch from inside HQ's own network on an attacker's behalf).
- `PageFetcher`: https only, no redirect followed (the target is reported and judged on its own), HTML and
  plain text only, size capped, scripts and styles dropped, NFC then whitespace folded so a span is counted in
  characters of the stored text. The page comes back as a `ThirdParty` context block, untrusted, capped at
  A1 when held (ADR-024), and as a stored `research_sources` row.
- `BraveSearch` and `TavilySearch` behind the provider-neutral `SearchProvider` (which extends the existing
  `ResearchSource` platform contract). Each asks `CredentialVault` for its key inside a gateway-opened scope
  and cannot form a request otherwise. Only the URL and the rank of a result are kept.
- `ArtifactFiler` (ADR-051 points 1, 2, 6): the append-only artifact. Every claim is typed: a **fact**
  cites a page of this task with a span and the exact excerpt, checked character for character against the
  page as read, and may not state a figure its excerpt does not carry; a **metric** comes only from a system
  of record (`erp`, `shop`, `hq`) with its reference, never from the web; an **inference** names the claims
  it rests on and may not introduce a figure none of them carries; a **recommendation** is labelled so. A
  claim that points at memory is refused. Each fact carries `trusted: false`, provenance `third_party`, the
  URL, the retrieval time, the page's content hash and the excerpt's hash; the artifact names its task and
  is digested. Bangla digits are read as the same figures.

**2. The content factory (`app/Content`, ADR-051 points 5, 6, 8, 9).**

- `ContentBrief` (append-only, versioned per brand): persona, the owner's claims policy, banned words.
  `ClaimsPolicy` matches a forbidden phrase where a word starts, case-insensitively, after NFC, in English
  and Bangla; a product line the policy does not cover, or a draft that names no line, **fails closed**. The
  policy is written by the owner; the one in the tests is `claims-policy.EXAMPLE.json`, marked so, never
  seeded.
- `ProductFact`: a price, size or ingredient enters a draft only from `erp`, `shop` or an
  `approved_source` that names the person who approved it. A research claim is never a product fact.
- `ContentChecks`: `content.numbers.v1`, built on `briefing.numbers.v1`, the same `NarrativeNumberCheck` that
  checks the Executive's briefing: every figure in the copy must equal a product fact or a cited research
  fact's value, exactly, nothing computed (a sum of two facts fails). Then the claims policy, then the banned
  words. A failing draft is stored `failed_checks` with its words exactly as written; nothing rewrites them.
- `ContentDraftService`: a draft is made from an approved brief, the words (labelled `generated` or
  `business`), product facts, cited research claims (facts and metrics only, from an artifact of the same
  business and brand) and assets (a product photo must be the real photo, an asset needs a recorded right to
  publish, Instagram takes JPEG only). A checked draft's words, assets, facts, checks and digest are **frozen
  by a database trigger**; an edit is a new draft with `parent_draft_id`, checked again; only a checked
  draft can be approved.
- `ContentDigest`: the fingerprint of exactly what a person approves (words in NFC, assets by id and
  checksum, platform, variant, brief version, business, brand); the publication digest adds the account.
- `LiveProductFacts`: the re-read of product facts at publish time. **Not wired**: it refuses, so nothing
  can be published even with every flag on, until gate P6-G3 wires it to the ERP and shop read tools.
- `Render/`: `CardRenderRequest` fixes what a Bangla card render must honour (NFC text, `lang="bn"`, a font
  with the Bengali script, JPEG, engine `chromium` with no field for another). `BrowserCardRenderer` calls the
  render service and refuses a card not laid out by a browser engine or laid out from other words (the
  service echoes the SHA-256 of the text in the rendered DOM). `deploy/content-render/` is the service:
  headless Chromium with Noto Bengali, its own compose file **not referenced by `deploy/docker-compose.yml`**,
  so no release builds or starts it, plus a self-test that renders the two words GraphicsMagick broke on 12
  July 2026 ("গার্ডেন", "তেল") for a person to look at at P6-G2.

**3. Publishing (`app/Content/Publishing`, ADR-051 points 8, 9, 10, 12).**

- `PostPublisher` adapters for a Facebook Page and an Instagram professional account, written against
  Meta's documented request shapes (noted in each class with what was verified on the page and what was not),
  registered nowhere. An answer lost in transit is `uncertain`; a refusal is `failed`; an accepted post id is
  never itself "published".
- `PublicationService`, in order, every step refusing rather than repairing: the flag; the draft approved
  by a person for this brand and platform; the stored draft matches its own digest; the approval's digest
  equals the digest of the stored draft for this account (a changed word, asset or account is different
  content); the checks run again against live facts; a provider resolved through `CapabilityResolver`; one
  publication per (draft, account, variant); read back, and only a read-back showing the approved words is
  `published`, otherwise `mismatch`.
- Reconciliation before any retry (docs/85 C.20): a `pending` or `uncertain` row is looked up first, by its
  post id when it has one, else among the account's recent posts; one match is adopted, none means `absent`
  (a retry may send), two or more is an `incident` for a person; `mismatch` and `incident` stop everything
  until a person resolves them.

**4. The five tools (`app/Tools/Research`, `Content`, `Publishing`), declared in `PhaseSixTools::DECLARED`,
registered nowhere:** `research.search` R2 read, `research.fetch_page` R2 read (output third-party),
`research.file_artifact` R3 write, `content.create_draft` R3 write, `publishing.publish_post` R5 write,
customer-facing, irreversible, `idempotencyKeyFrom` including `content_digest` so the gateway's approval
(bound to the payload hash) is bound to the exact content; its `preview()` shows the approver the exact
words, the account and whether the digest matches the stored draft. None is agent-executable. Each keeps a
task or draft inside the business the call is scoped to.

**5. Schema:** one additive migration, seven tables (`research_tasks`, `research_candidates`,
`research_sources`, `research_artifacts`, `content_briefs`, `content_drafts`, `content_publications`) and
three Postgres triggers (artifacts and briefs append-only; a draft's words frozen). `config/content.php`
holds the Phase 6 settings apart from `config/hq.php`, so the foundation touches no shared setting.

## Fixed on 26 September, in the resumed work

1. **A post could be sent twice after a crash.** A `content_publications` row is written `pending` before
   the send. If the process died, or the publisher threw, between the send and the record, the next call
   found a `pending` row and sent again. Now `pending` is reconciled exactly like `uncertain` before anything
   is sent (test: a pending row plus one matching post on the platform is adopted, nothing sent).
2. **A read-back failure hid an accepted post.** The post id was written only together with the read-back,
   so a read-back that threw left a `pending` row naming no post. Now the id is written first, the read-back
   failure leaves `uncertain` with the id, and reconciliation reads that id back before looking at recent
   posts (test: the read-back fails once, the row keeps the id, the retry reads it back and sends nothing).

## Not built here, on purpose

- No registration, permission seeding, flag row, provider registration, credential or `.env` value: each is
  a gate's step (P6-G1 research, P6-G2 drafting and the render service, P6-G3 publishing), taken in a reviewed
  release. The three permissions the descriptors name (`hq.tools.research.read`, `hq.tools.content.write`,
  `hq.tools.publishing.write`) are not seeded until a tool is registered.
- No `media.generate_image`, `media.render_card` tool, `publishing.create_draft_post`, `publishing.delete_post`
  or `publishing.read_post_insights` (docs/85 D): the render is a class behind a service not started; the
  compensating delete and the insights read follow with P6-G3.
- No model call anywhere: the words of a draft come in as an argument, labelled. The content agent of
  docs/85 C.22 is not seeded.
- No screen: stream V's posture page and dictionary are where the research tasks, drafts and publications
  will be shown, once merged.
- The live re-read of product facts at publish time is a refusal, not a stub returning nothing: a
  publication without it is refused.
- The page fetcher reads the whole response before checking its size against `max_page_bytes`; streaming the
  body and stopping at the cap is a P6-G1 refinement.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New tests: `tests/Feature/Research/ResearchEngineTest.php` (15), `ResearchArtifactTest.php` (11),
  `tests/Feature/Content/ContentDraftTest.php` (15), `PhaseSixInertTest.php` (9), `PublishingTest.php` (15),
  `BanglaRenderTest.php` (5): **70 tests**, every HTTP call faked, stray requests failing the test, so nothing
  reaches the internet. `PhaseSixInertTest` names each switch that keeps Phase 6 off: not in config, not in
  the registry, not in the gateway, no scheduled job may name a tool, no agent holds one, the Executive's and
  Analytics' roles forbid publication, every flag off, no platform registered, every tool refuses with the
  flags off and sends nothing, publication refuses even with every flag on, a task and a draft stay inside
  their business.
- Mutations, each applied, the tests run, the code restored: the research engine running with the flag off
  (caught); the approval digest not compared (caught); `publishing.publish_post` added to `config('hq.tools')`
  (caught); the boundary letting any URL through (caught); a fact's excerpt not checked against the page
  (caught); the live re-read wired as an empty list (caught); the claims policy no longer failing closed
  (caught); a pending row re-sent without reconciliation (caught). 8 of 8.
- Full Pest suite on `hq_test_i`: **1345 passed, 11,642 assertions, 0 failed**, 428 s (1275 on the branch base `37a2be6` plus the 70 new). `vendor/bin/pint --test`: passed.
  `npx tsc --noEmit`: clean. `npx vite build`: built. No em dash in any file of the stream.

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream T: research engine, content factory, publishing adapters, Bangla render, ADR-051 accepted | yes | not yet (branch pushed for review) | no | no | no | no | no | no |

Nothing an agent could observe or use changed: no prompt, no registered tool, no policy, no runtime path, no
agent row, no shared config value. Stream T needs nothing from Gate 13 (docs/88 A point 8: schema, inert
adapters and infrastructure).

## Open questions

1. `docs/85` I stands: the claims policy per product line, the search provider (and storage rights), the
   source list per brand, who approves content per brand, retention of unapproved drafts. Joy.
2. `publishing.publish_post` carries no approval id into the publication row today (the gateway does not
   hand the tool the approval it ran under); the digest in the payload is what binds the approval. Whether
   the row should also name the approval is a small gateway change for P6-G3.
3. Instagram needs the card at a public https URL Meta can fetch; where HQ serves approved JPEGs from
   (R2, the media library, or the render service's own store) is a P6-G2 decision.
