# ADR-051: Research is evidence, never truth, and published content states product facts only from systems of record

**Status:** proposed (21 Sep 2026, `docs/85`, the Phase 6 design and readiness packet ordered in
`docs/83` point 5). Nothing is built: no research, content or publishing tool, adapter or flag exists.

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
