# ADR-049: OpenAI serves by an audited lever, and a measurement runs on one provider

**Status:** accepted and **implemented** (21 Sep 2026, `docs/81` C.7), **nothing switched on**: the
OpenAI rows are seeded inactive, `ai.runtime` stays off, every agent stays paused at A0, there are no
grants. Amends ADR-019 (the router's cross-provider fallback) for the case where it would mix two
models inside one measurement.

## Context

On 21 September the owner decided to stop using the Anthropic API for now and use OpenAI's: "i want
to skip the antropic api for now, interested to chatgpt api to use, guide me, i will add for you. i
will use antropic after, i will let you know." The Anthropic account is blocked twice over (no
credit, and the month's spend limit used), and every earlier safety gate measured a Claude model.

ADR-019 anticipated a second provider: a driver class plus `model_tiers` rows. It also chose a
fallback that tries a DIFFERENT provider first on an outage (`docs/48` J.2), which is right for
availability and wrong for evidence: with two providers' rows active, one gate's cases or one shadow
week's answers could come from two models without anybody having decided it. And the readiness
check, the gate's bypass step and the readiness table all named Anthropic by construction, so they
would have certified a key HQ was no longer using.

## Decision

1. **An OpenAI driver** beside the Anthropic one, on the Responses API with `store: false`, the key
   read only through the vault and sent only as a Bearer header, prices read from OpenAI's pages on 21
   September (cache writes included: GPT-5.6 bills them at 1.25x input), registered at the 272K
   short-context band that `CostCalculator` prices correctly. Structured replies are `strict` only
   inside the documented strict subset; HQ's agent schema is outside it (its arrays carry no `items`),
   so agent turns go without the guarantee and HQ's own validator and one repair stay the check, as
   they are for every provider. Reasoning effort is one setting, fixed before a gate.
2. **Which provider serves is which rows are active**, read by `ServingProvider`: one, none, or
   more than one. A measurement needs exactly one: the gate's bypass step checks nothing when two
   serve, and a shadow period refuses to open unless one does.
3. **Only a person moves it**, with `hq:ai:provider <provider> --apply --by=<enabled person>
   --reason=...`: every chat-tier row of the chosen provider on, every other provider's off, in one
   transaction, audited under that person. Dry run by default. Refused without a driver, a stored key,
   a row at each chat tier, or while a shadow period is open. It touches no switch, agent or grant.
4. **Seeding switches nothing on.** A new row is created active only for the provider that already
   serves (Anthropic on an install where nothing serves yet).
5. **Readiness follows the serving provider**: the readiness row, the provider check and the gate's
   bypass step check the provider that would serve. OpenAI's billing refusal is a 429 that shares its
   status with throttling; the error code decides, and a billing block is `provider_blocked_by_credit`,
   never an invalid key and never an outage to fail over from.

## Alternatives

- **Rely on priorities** (make OpenAI's rows cheaper or first). Rejected: the first choice would move,
  but any 429 or 5xx would still fall over to the other provider, so a measurement could still mix
  models, silently.
- **Remove the cross-provider fallback.** Rejected here: it is the availability design ADR-019 chose,
  and with one provider's rows active it cannot fire anyway. A future decision may revisit it.
- **Replace Anthropic.** Rejected: the owner said he will use Anthropic again. Going back is the same
  lever, and the Anthropic driver is unchanged.
- **Change the agent schema so OpenAI can enforce it strictly.** Rejected: it is the contract every
  earlier gate measured; changing it to suit a provider would change what is measured.

## Consequences

Gate 11 attempt 2, on the release that carries this, measures an OpenAI model for the first time
(`gpt-5.6-terra` for the Executive's turns, which route to the medium tier, at the configured reasoning
effort). Earlier gates' results are evidence about Claude and are not carried over. Returning to
Anthropic later is the lever the other way and needs its own complete gate before any shadow runs on
it. A shadow period cannot outlive a provider change, because the lever refuses while one is open.
