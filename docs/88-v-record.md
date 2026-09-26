# 88 V - Stream V record: the operator screens in Bangla and English, and the activation posture screen (22 September 2026)

Branch `ui/operator-screens-bn-en`, worktree `hq-wt/k` (its own `vendor` and `node_modules`, installed with
`composer install` and `npm ci`, no junctions), test database `hq_test_k`. Built on `origin/main` at
`37a2be6` under `docs/88` A points 1, 7, 8, 20 and 21.

## What was built

**1. Bangla and English for every screen (`resources/js/lib/i18n.ts`, `resources/js/lib/i18n/bn.json`).**
HQ had no translation layer at all. The English text on a screen is now its own key: `t('Agents')` gives
the Bangla entry when the viewer chose Bangla, and the English text otherwise, or when no entry exists yet,
so no screen is ever blank in either language. The shared building blocks translate their string props
themselves: the page header and section headers, the dashboard panels, stat tiles, table column labels and
empty messages, notes, the status badge, the sidebar (section names and items), the footer link and the
breadcrumbs. So most of every screen became bilingual without editing the screen, and translating a screen
means adding its strings to the dictionary. The dictionary holds 335 entries plus the brand.

- **The choice.** An "English / বাংলা" switch in the top bar of every page. It is kept in this browser,
  like the light and dark choice already is: HQ has no per-user language setting, and the choice changes
  how a page reads, never what HQ does. It sends no request and saves nothing on the server. The page's
  `lang` attribute follows it.
- **English digits in both languages (Joy's rule).** `translate()` turns any Bangla digit into an English
  one whatever produced it. Every number and date on every screen now names an English locale: the eight
  places that used the browser's locale (`toLocaleString()` in pagination, the run page, the audit log, the
  customer pages and the 2FA page, and `formatDateTime`) would have printed Bangla digits in a browser set
  to Bangla. A test refuses any new one.
- **NFC.** Every Bangla entry is normalised to NFC when the dictionary loads, and a test holds the file
  itself to NFC.
- **Brand.** "Bor de Guna" in English, "বর দে গুনা" in Bangla, never বোর; held by a test.

**2. The activation posture screen (`/observe/posture`, "Activation posture" in the Observe section).**
One read-only page for ChatGPT's list, in the order of point 7:

| Point 7 item | Where it is on the page | Read from |
| --- | --- | --- |
| Readiness | "Credential readiness": targets, verified, would be attempted, unsupported, and each incident | `CapabilityReadiness::inventory()` (the table `hq:credentials:health` prints) |
| Execution context | "Agents and what they may never do": each agent's status, autonomy ceiling, enabling user, role and tools held; the full execution context of a run stays on the run page | `Agent`, `RolePolicy` |
| Evidence | "Gate evidence": the latest finished run of every suite for every agent, with passed, failed, unmeasured, model and who measured it | `ShadowPeriods::evidenceFor()` plus the run rows |
| Approvals | stays on the Approval Center (already bilingual through the shared components) | |
| Capability state | "Capability state": every gateway tool with its risk, whether it writes, whether it is customer-facing, whether an agent may run it, whether it is people-only forever, its switch, and which agents hold it | the gateway's descriptors, `ToolRegistration`, the agents' tool lists |
| Role restrictions | "Role restrictions": for every role the policy names, each role-boundary kind shown as refused before the model or not refused by the role, with the policy version; a note says "not refused by the role" is not a permission | `RolePolicy`, `RoleBoundary::KINDS` (a fourth kind from stream W appears by itself, and has its label already) |
| Autonomy evidence | linked from "Gate evidence"; the evidence policy and the active grants are in the switches | `AutonomyEvidencePolicy`, `AutonomyGrant` |
| Provider readiness | "Model provider": which provider serves, the reasoning effort, each provider's key and last check, every model row on or off | `ProviderRegistry`, `ServingProvider`, `CredentialHealth` |
| Shadow status | the switches (open periods) and "What stands between each agent and its shadow week": the exact reasons, in the console's own words | `ShadowPeriods::refusals()`, the same check `hq:shadow:open` refuses on |
| Failure and reconciliation state | "Uncertain and failed work": uncertain tool calls (and the latest ten), dead events, open alerts, failed queue jobs | `ToolCall`, `DomainEvent`, `Alert`, `failed_jobs` |
| Audit references | "Audit references": the latest fifteen audit rows that changed the posture (flags, agents, shadow periods, autonomy, evidence policies, the model provider, credentials), each linking to the audit log filtered by its action | `AuditLog` |

At the top, one line says either that production is inert or exactly what is switched on (the runtime, Meta
inbound, a messaging provider, a store that accepts AI orders, a grant, a shadow period, or an agent that is
not paused), so the page cannot call production inert while something runs.

**Permissions.** The page is a GET behind `auth`, both 2FA gates and `hq.agents.view`, a permission that
already exists, so no role needs reseeding (owner, operator and brand manager see it; finance viewer and
support agent get a 403). Two parts belong to the screen they come from: the credential readiness rows
appear only for `hq.credentials.view`, and the audit references only for `hq.audit.view`; an audit
reference shows id, time, action, actor and target, never the row's before, after or reason. The route,
the page and the sidebar link are each held by a test.

**Read-only.** The page reads classes that already decide each fact, so it cannot disagree with the
console; it opens, switches, grants and registers nothing. A test reads the page and proves no row was added
or changed in the audit log, flags, agents, grants, shadow periods, tool calls, credentials, approvals or
consent events. A second test pins every non-GET web route that exists today: stream V adds none, and any
future write route must be added to that list on purpose by the change that needs it.

**Also translated through the shared components** (every literal they pass is in the dictionary, held by a
test): AI health, control plane, autonomy evidence, credentials, the Approval Center list, the agents list
and the audit log. Text written inline in those pages' own markup (a few headings and sentences outside the
shared components) is still English; those, and the screens not listed, fall back to English until their
strings are added.

## Not built here, on purpose

- No screen for the other streams' new capabilities (Q's WhatsApp threads and delivery states, R's order
  quote, status and cancel, S's ad spend, T's research and drafts with the claims check, U's voice
  transcripts). Their screens follow once those streams merge, on this same dictionary and the same
  read-only rule.
- No per-user language setting on the server: it would be a new write path (a profile field and a
  migration). If Joy wants the choice to follow him across devices, it is a small later change.
- Shadow refusal reasons and readiness reasons are shown in English, as the console words them: they are
  sentences the server composes, and a translation could drift from the check that produced them.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New tests: `tests/Feature/Observe/PostureScreenTest.php` (9) and
  `tests/Feature/Foundation/BanglaDictionaryTest.php` (7).
- Mutations, each applied, the tests run, the code restored: readiness shown without the credentials
  permission (caught); audit rows carrying the reason (caught); shadow readiness not taken from the console
  check (caught); a write route added (caught); a number formatted in the browser's locale (caught); a
  Bangla digit in the dictionary (caught); a covered screen string with no entry (caught). 7 of 7.
- Full Pest suite on `hq_test_k`: **1291 passed, 14,391 assertions, 0 failed**, 366 s (1275 on `main` plus the 16 new).
- `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: clean. `npx vite build`: built. No em dash in
  `resources/js` (a test now holds it; HQ had no such guard before).

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream V: bilingual screens and the activation posture page | yes | not yet (branch pushed for review) | no | no | no | no | no | no |

Nothing an agent could observe or use changed: no prompt, tool, policy, runtime path or agent row. Stream V
needs nothing from Gate 13.

## Open questions

1. Should the language choice follow the person (a server-side profile setting) rather than the browser? It
   needs a migration and one profile write, so it is left for a decision.
2. The inline text still in English on the covered pages: translate it now in a follow-up, or with each
   page's next change?
