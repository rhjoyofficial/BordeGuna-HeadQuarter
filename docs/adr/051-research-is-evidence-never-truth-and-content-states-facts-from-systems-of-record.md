# ADR-051: Research is evidence, never truth, and published content states product facts only from systems of record

**Status:** ACCEPTED with clarifications (22 Sep 2026, ChatGPT's instruction recorded in `docs/88` A.5).
Proposed 21 Sep 2026 in `docs/85`, the Phase 6 design and readiness packet ordered in `docs/83` point 5.
The foundation is built inert by stream T (`docs/88-t-record.md`): nothing is registered, credentialed,
live-called, agent-held, agent-granted or activated.

## The clarifications accepted with it (22 September 2026, `docs/88` A.5)

1. Research output is evidence, not truth.
2. Every factual claim intended for publication carries provenance naming the source, the source span
   (or equivalent bounded evidence), the retrieval time and the artifact and task it belongs to.
3. External web content is untrusted content.
4. A source can never grant authority, permission, role, consent, business scope, autonomy or task
   expansion.
5. Product facts enter publishable content only from trusted ERP or shop contracts, or from explicitly
   approved sources.
6. Model memory is never a source of truth.
7. Research fetching stays bounded by the research task and its permitted sources and results; there is
   no open browsing.
8. Publication is R5 and needs approval of the exact final post or content artifact.
9. The approved content is fingerprinted (digested) so that an approval cannot be reused for changed
   content.
10. A publication is read back and reconciled after execution.
11. Research providers stay replaceable adapters behind a provider-neutral interface.
12. A future publishing capability stays unavailable to the Executive unless explicitly activated.

## Where each point is implemented (stream T, 22 September 2026; all inert)

| Point | Mechanism |
| --- | --- |
| 1, 3 | `ResearchService::fetch()` returns a page only as a `ThirdParty` context block; `ArtifactFiler` marks every fact `trusted: false` |
| 2 | `ArtifactFiler`: a fact names a source of its own task, a span, the exact excerpt (checked against the page as read), the retrieval time, the content hash and the excerpt hash; the artifact names its task |
| 4 | Nothing reads a page for instructions; the task's scope, budget and source list are fixed when a person opens it (test: an injected page changes nothing, and the bundle is capped at A1) |
| 5 | `ProductFact`: `erp`, `shop`, or `approved_source` with the approving person; `ContentChecks` (`content.numbers.v1`, built on `briefing.numbers.v1`) refuses any figure not in that evidence |
| 6 | `ArtifactFiler` refuses a claim that points at memory |
| 7 | `ResearchBoundary` (approved list or the task's own results; no redirects followed; `AddressGuard` refuses internal hosts) |
| 8, 9 | `publishing.publish_post` R5, approval-required, carrying `content_digest`; `PublicationService` recomputes it from the stored draft and the account and refuses any difference |
| 10 | `PublicationService`: `published` only after a read-back shows the approved words; the post id is written before the read-back; a pending or uncertain row is reconciled (by its id, else by the recent posts) before any retry |
| 11 | `SearchProvider` interface; `BraveSearch` and `TavilySearch` adapters; the choice is the owner's (`content.research.provider`, unset) |
| 12 | No agent holds any Phase 6 tool; the Executive's role forbids publication (`hq.roles`) |

## Context

Phase 6 adds two things that read and write words at scale: a research engine that fetches pages from
the open web, and a content factory that publishes to the brands' Pages. Both meet the risks HQ's
invariants already name: untrusted text carrying instructions (ADR-024, ADR-039, ADR-041), memory
passing for fact (ADR-033), and a request that "succeeded" without the effect being real (ADR-031).
Two measured facts sharpen them: a price, weight or health claim in a public post is a statement by
the business; and Bangla rendered by a library without a shaping engine prints wrong words (July
2026, the n8n pipeline).

## Decision

1. **A research task is an explicit trusted task** with a versioned contract, a closed input, a scope
   and a budget. It fetches only URLs on an owner-approved source list or among its own search
   results; nothing it reads can create a task, widen its sources or change its scope.
2. **A research artifact is append-only and typed.** Every claim is fact (with the source span that
   supports it), metric (from a system of record, with lineage), inference or recommendation, and says
   which. Sources are `ThirdParty`, customer content `Customer`, model summaries `Generated`: none
   trusted. Memory may suggest a search; it never becomes a claim.
3. **Product facts come only from systems of record.** A draft's prices, sizes, stock and counts are
   read from the ERP or the shop at draft time and again at publish time; a claims check (the content
   version of `briefing.numbers.v1`) refuses any figure that is neither such a metric nor a cited fact.
4. **Publishing is R5, one approval per exact post.** The approval binds text, asset checksums,
   account and time; HQ schedules and publishes, then reads the post back; an uncertain publication is
   reconciled by its logical key before any retry. Deletion is R5 and never autonomous.

## Alternatives

- **Open browsing for the research agent.** Rejected: an unbounded fetch is a hidden capability and
  an injection surface.
- **Letting research supply product facts** when the ERP lacks them. Rejected: a gap in the catalogue
  is a data task for the business, not a reason to publish a web page's number as ours.
- **Publishing as R4.** Rejected in this proposal: a post, once seen, cannot be unseen; ChatGPT may
  overrule with reasons.

## Consequences

Drafts fail more often at first (missing cost or product data, claims outside the policy), and each
failure goes to a person rather than being smoothed over by the model. The owner must write a claims
policy per product line before the first draft. Storing search results may need a paid plan with
storage rights; by default only spans and hashes are kept.
