# 98 - The ConnectorRegistry and the migration of the legacy provider shapes (3 October 2026)

Stream W3. ChatGPT's ruling D2 of 3 October (`docs/97` §6): **"ConnectorRegistry: APPROVED AND MANDATORY for new
integrations.** Capability -> ConnectorRegistry -> Provider/Adapter -> Direct API / MCP / n8n / Internal -> Tool
Gateway. No blind rewrite of legacy integrations now, but every NEW external adapter enters through the registry;
document the migration path for the five legacy registry shapes and converge over time." This document is that
contract, the model, the migration plan and the rule. Code: `apps/hq/app/Connectors/`, `config/hq-connectors.php`,
`CapabilityReadiness::connectors()`; tests: `tests/Feature/Connectors/`.

Production posture is unchanged: the registry ships with **no connector registered**, no flag is added or flipped,
no tool is registered, no legacy class is rewritten.

## 1. The model

```
Model -> Agent runtime -> Tool Gateway (authorize) -> ConnectorRegistry (which provider?) -> Adapter -> Transport -> Provider
                                ^                              |
                                |                              v
                    policy, EEC, consent, approval    credential ids (never values) -> CredentialVault scope -> Adapter
```

Four layers, never conflated (`docs/96` §5):

| Layer | What it is | Example | Where it lives |
|---|---|---|---|
| Capability | a stable business contract: a name and a PHP interface | `voice.transcribe` / `SpeechToText` | `App\Connectors\Capabilities` |
| Provider | who performs it | Google, ElevenLabs | a row in `config/hq-connectors.php` |
| Adapter | the class that reaches the provider; implements the capability's interface | `GoogleSpeechToText` | `app/<Domain>/...` |
| Transport | how the adapter talks | `direct_api`, `mcp`, `n8n`, `internal` | `App\Connectors\Transport`, per row |

**Resolution is not authorization.** The registry answers "which implementation could serve this capability, in this
scope, right now". The Tool Gateway still decides whether this caller may invoke it (policy, EEC, consent, autonomy,
approval). In the D4 order (`docs/97` §6) resolution sits inside the credential preflight step: the registry returns
the credential ids that made the connector ready, the gateway opens exactly those in the vault, and only then can
the adapter ask the vault for a secret. MCP and n8n are transports behind the same gateway; they never grant
authority.

## 2. The contract

### 2.1 Capability names (the catalogue)

Names are `domain.object.verb` (or `domain.verb`), lower case, dot-separated, never renamed once used; a changed
contract is a new name.

| Capability | Contract | Status |
|---|---|---|
| `ai.complete` | `App\Ai\Providers\ModelProvider` | contracted; served today by the legacy `ProviderRegistry` |
| `voice.transcribe` | `App\Voice\SpeechToText` | contracted; legacy `SpeechProviders` slot, empty |
| `voice.synthesize` | `App\Voice\TextToSpeech` | contracted; legacy `SpeechProviders` slot, empty |
| `commerce.shop` | `App\Commerce\Contracts\CommercePlatform` | contracted; legacy `CommerceRegistry` |
| `erp.read` | `App\Erp\ErpPlatform` (extracted 4 Oct on ruling 9.2; see §5.5) | contracted; legacy `ErpRegistry` |
| `research.search` | `App\Research\SearchProvider` | contracted; legacy `ResearchService::PROVIDERS` (§5.3) |
| `messaging.whatsapp.send` | `App\Conversation\Channels\OutboundChannelAdapter` | contracted; nothing registered |
| `messaging.messenger.send` | `OutboundChannelAdapter` | contracted; nothing registered |
| `messaging.instagram.send` | `OutboundChannelAdapter` | contracted; nothing registered |
| `comments.manage` | `App\Platform\Contracts\CommentChannel` | contracted; legacy `PlatformRegistry`, empty |
| `content.publish` | `App\Platform\Contracts\PublishingPlatform` | contracted; legacy `PlatformRegistry`, empty |
| `ads.read` | `App\Platform\Contracts\AdsReadPlatform` | contracted; legacy `PlatformRegistry`, empty |
| `storage.object` | `App\Platform\Contracts\StorageDriver` | contracted; legacy container binding (§5.7) |
| `media.image.generate` | none yet | **reserved** |
| `media.video.generate` | none yet | **reserved** |
| `voice.call` | none yet | **reserved** |
| `courier.track` | none yet | **reserved** |

A reserved name cannot be registered (the registry refuses at load) and resolves to `unknown_capability`. Writing
its interface is the first step of building it (`docs/97` §3 item 1) and moves it into the contracted list.

### 2.2 A connector row

```php
[
    'capability' => 'voice.transcribe',               // a catalogue name with a contract
    'provider' => 'google_chirp',                     // lower-case key, unique per capability
    'adapter' => GoogleSpeechToText::class,           // must implement the capability's contract
    'transport' => 'direct_api',                      // direct_api | mcp | n8n | internal
    'credentials' => [['kind' => 'google_speech_access_token', 'account_ref_from' => '=hq']],
    'priority' => 10,                                 // lower is tried first
    'enabled_by' => 'flag:connectors.voice.google',   // flag:<feature flag> | config:<config key>
    'fallback' => false,
    'businesses' => null,                             // optional: slugs served; null = any
    'platform' => null,                               // optional: a platform_accounts.platform
]
```

- `credentials.kind` is an `integration_credentials.provider` value (the kinds W2b lists in `CredentialKinds`).
  `account_ref_from` is the `CredentialRequirement` grammar narrowed to what a capability request carries:
  `=literal`, `business.slug`, `brand.slug`, `platform_account.ref` (no `args.*`: a connector is chosen before
  any tool argument exists). `optional: true` lets a credential be absent.
- `enabled_by` is required. There is no "always on": a connector is off until a person switches it on, and a flag
  rolled out to some businesses serves only those businesses.
- A malformed row throws at load, naming the row and field. A typo must never read like a provider that is off.

### 2.3 Resolution

`ConnectorRegistry::resolve(new ConnectorRequest($capability, businessId, brandId, platformAccountId))`:

1. Unknown or reserved name -> `unknown_capability`.
2. Nothing registered for the capability -> **`no_connector`** (typed, returned as data, never thrown).
3. The scope narrows: an account implies its brand, a brand its business; parts that disagree are refused.
4. Each connector is checked: in scope (`businesses`, `platform`), switched on (for this business), every required
   credential ready by the same rules as `CredentialResolver` (metadata only; missing, valueless, expired, ambiguous
   or known rejected = not ready). A connector that fails carries its reason into `rejected`.
5. Primaries first, then fallbacks. In a tier, the best priority wins; **two ready connectors at that priority are
   refused (`ambiguous`), never chosen**, the `CapabilityRouter` / `AmbiguousCredential` precedent.
6. Ready -> `resolved`, with the connector and the credential ids to open. None ready -> `unavailable` with every
   reason.

`ConnectorResolution::toArray()` is safe for an audit row, a tool result or a model prompt: names, ids and reasons.

### 2.4 `preferProvider` (ruling 9.2, `docs/101` §11, "approved narrowly")

`ConnectorRequest` carries one optional field that names a provider: `preferProvider`, a provider key. It exists for
the two legacy shapes that select by name (`model_tiers.provider`, `content.research.provider`), so they can converge
without losing their configured choice. It is applied in step 5 only, after every check of step 4 has already decided
which connectors are ready:

- **MAY** choose among the ready connectors of the tier that serves (primaries if any primary is ready, otherwise
  fallbacks), which includes breaking a tie at the best priority that would otherwise be refused as `ambiguous`.
- **MUST NOT** override primary-vs-fallback: a preferred fallback is never chosen while any primary is ready.
- **MUST NOT** select a connector that is not ready (off, out of scope, not switched on for this business, a
  required credential missing/valueless/expired/ambiguous/known rejected). Such a connector is not in the list the
  preference is applied to.
- **MUST NOT** bypass policy, permission, consent, credential, readiness or scope. Resolution is not authorization
  (§1): the preference never reaches the Tool Gateway's checks, and the credential ids returned are exactly those the
  chosen connector's own readiness check found.
- **MUST NOT** be model-controlled. It is set only by HQ code from trusted configuration or routing, never from a
  model's tool arguments. `ConnectorPreferenceGuardTest` holds this structurally: no tool input schema, the agent
  response schema or the NL job-spec schema has a field that names a provider; no file outside `App\Connectors`
  sets it unless allowlisted (the allowlist is empty, and no allowlisted file may live in `app/Tools`, `app/Agents`
  or `app/Http`); no statement that sets it reads a payload or arguments variable.

A preference that cannot be honoured changes nothing: the answer is exactly the answer with no preference, and
`ConnectorResolution::$preference` (also in `toArray()`) records why, for the audit row: `honoured`, or `not honoured:`
with the reason (not ready and why, a fallback while a primary is ready, not registered for the capability). A
preferred name that is not a lower-case provider key is refused when the request is built. Tests: the
`preferProvider` section of `ConnectorRegistryTest` (one test per MAY / MUST NOT) and `ConnectorPreferenceGuardTest`.

## 3. Readiness

`CapabilityReadiness::connectors()` returns `ConnectorReadinessRow`s: one per registered connector (capability,
provider, transport, adapter, state), then one per provider a legacy shape serves (state `legacy`, with the shape
that resolves it). It is a separate list beside `inventory()`, so **no existing row, incident or exit code
changes**; a test compares `inventory()` before and after a connector is registered.

States for a registered connector: `disabled` (switch off); `scope_dependent` (credentials looked up per business,
brand or account, checked at resolution); `ready` (on, needs no credential); otherwise the `ReadinessRow` credential
states (`credential_missing`, `credential_invalid`, `credential_unverified`, `verified`, the provider states), the
worst required credential deciding. Nothing in readiness calls a provider.

Today the view lists: `commerce.shop` x2 (laladia, bionic), `erp.read` x1, `ai.complete` x2 (anthropic, openai),
all `legacy`, and no registry row. The speech slots and the platform registry are empty and report nothing. Neither
the `hq:credentials:health` command nor the Credentials screen shows the view yet (a follow-up, §7 Q4).

## 4. The rule for every new adapter

1. Name the capability (or use an existing one). A reserved name gets its interface written first.
2. Write the adapter implementing that interface. It asks `CredentialVault` for its secret; it never resolves one.
3. Register it as a row in `config/hq-connectors.php`, switched by a flag that ships OFF.
4. Callers ask the registry for the capability; they never name the adapter or the provider.

A structural test (`ConnectorStructureTest`) fails the suite when new code in `app/`:
- declares a class named `*Registry` or `*Providers` other than the five legacy shapes, the ConnectorRegistry and
  `ToolRegistry` (tools, not providers);
- names two or more adapters of one contract in one class (`X::class` or `new X`), i.e. chooses between providers,
  other than `ResearchService` (the sixth legacy shape) and the legacy bridge;
- binds an adapter or an adapter contract in a service provider or bootstrap file, other than the existing
  `StorageDriver` -> `LocalStorageDriver` binding.

Each allowlist names the existing code it admits. Adding to one is a decision recorded here, not a way to make the
test green. The detectors themselves are tested on fixture source (import, alias, closure, comment).

## 5. Migration plan for the legacy shapes

None is rewritten in this stream. Each is reported through a read-only bridge (`App\Connectors\Legacy\LegacyConnectors`)
and converges in its own step, in this order (lowest risk first). Every step uses the same proof:

- **A parity test written first**, on both paths at once: for every input the legacy class accepts, the registry
  resolves the same adapter class with the same credential pairs, and refuses where the legacy class refuses.
- **The domain's existing tests unchanged and green** (listed per step).
- **`CapabilityReadiness::inventory()` byte-identical** before and after.
- Then the legacy class becomes a thin view over the registry (or is deleted), its allowlist entry removed in the
  same commit, and the bridge method deleted.

| # | Shape | Today | Step | Risk | Equivalence proved by |
|---|---|---|---|---|---|
| 5.1 | `SpeechProviders` | two slots, both empty (gate P7-G2) | P7-G2 activation registers rows for `voice.transcribe` / `voice.synthesize` instead of calling `register*()`; `VoiceNoteIngestion` asks the registry; the class is deleted | low: nothing is registered, nothing live | `VoiceFoundationTest` (inert, no audio leaves HQ, one transcript per redelivery), a parity test that "no row" = "empty slot" |
| 5.2 | `PlatformRegistry` | empty; `CapabilityResolver` / `CapabilityRouter` read it | it becomes a view over registry rows that carry a `platform`; resolver and router unchanged (account-level grants stay theirs) | low-medium: empty, but the router's fail-closed rules depend on it | `CapabilityRegistryTest`, `CapabilityRoutingTest`, `ChannelAdaptersInertTest` unchanged |
| 5.3 | `ResearchService::PROVIDERS` (sixth shape, not in `docs/95` §10) | private const map brave/tavily, chosen by config `content.research.provider` | rows for `research.search`; the configured choice becomes priority or a named provider (§7 Q2) | low-medium: research is inert | the research tests; a parity test per configured provider |
| 5.4 | `ProviderRegistry` (AI) | config class list; `model_tiers.provider` selects; live in every gate | rows for `ai.complete`; `ProviderRegistry` keeps its key -> driver map but is populated from the rows; `ModelRouter` unchanged | **high**: every agent run and gate; do it after the shadow week, never before a gate | the AI and gateway suites, `OpenAiReadinessTest`, `CapabilityReadinessTest` model rows, a replay of the last gate's recorded cases |
| 5.5 | `ErpRegistry` | one class, token per business | ✅ interface extracted (stream W6, ruling 9.2): `ErpPlatform`, which `ErpConnector` implements and every consumer names; still to do: a row for `erp.read` with `business.slug` credentials | medium-high: live ERP reads, `take_order` read-back | `tests/Feature/Erp`, `TakeOrderTest`, `OrderCompletionTest`, readiness business rows identical |
| 5.6 | `CommerceRegistry` | hardcoded adapter list; `stores.adapter` selects per store | needs a store scope in `ConnectorRequest` and a `store.slug` credential source; rows per adapter key | **highest**: the order-taking path | `tests/Feature/Commerce` (incl. `TakeOrderTest`), readiness store rows identical, the unsupported-store rows unchanged |
| 5.7 | `StorageDriver` container binding | `AppServiceProvider` binds `LocalStorageDriver` | a row for `storage.object`, transport `internal`, flag on; the binding asks the registry | low | the media tests |

### 5.5a `ErpPlatform` (stream W6, 4 October)

`App\Erp\ErpPlatform` holds exactly the public methods core logic already called on `ErpConnector`, with the same
signatures, DTOs and failure classes, plus the three contract-version constants (`ORDER_READS_CONTRACT`,
`SALES_ORDER_DRAFTS_CONTRACT`, `SALES_ORDERS_CONTRACT`), which are HQ's vocabulary for order bodies and are
inherited by the class unchanged:

`overview`, `lowStock`, `lookupProducts`, `lookupSku`, `readPrice`, `readAvailability`, `checkCustomerEligibility`,
`createSalesOrderDraft`, `readSalesOrderDraft`, `quoteSalesOrder`, `recordSalesOrder`, `readSalesOrder`,
`readSalesOrderByKey`, `cancelSalesOrder`, `runReport`, `rawRead`, `probe`.

Public methods nothing outside the class called stay on `ErpConnector` only (`connectorName`, `businesses`,
`stock`, `products`, `product`, `customers`, `customer`, `tickets`, `openTicket`, `pendingApprovals`, `reports`).
`ErpRegistry::for()` returns `ErpPlatform`; `ErpRegistry::ADAPTER` names the class so the legacy bridge does not;
every tool (`ErpOrderReadTool`, `PollErpTool` and their children, the order tools), `ErpOrderReadBackFact` and the
`erp.read` capability depend on the interface. No behaviour change: the ERP contract suites (`ErpContractFidelityTest`,
`OrderReadsContractTest`, `SalesOrderContractTest`, `SalesOrderDraftContractTest`, `ErpConnectorTest`,
`OrderReadToolsTest`, `TakeOrderTest`, `OrderCompletionTest`) pass unchanged. `ErpPlatformStructureTest` fails the
suite when anything in `app/`, `config/`, `routes/`, `database/` or `bootstrap/` outside `app/Erp` names
`ErpConnector`, in code or a docblock. Not done (the remaining §5.5 step): an `erp.read` connector row; `ErpRegistry`
still builds the one client itself.

## 6. What this stream did not do

No provider registered, no flag added or flipped, no tool registered, no gateway wiring (no tool resolves through
the registry yet: the first new adapter does, under §4), no change to any legacy class, to `inventory()`, to the
health command or to a screen.

## 7. Decisions for ChatGPT

- **Q1 Priority direction and tie rule.** Lower number is tried first; a tie at the best ready priority is refused,
  not chosen; a fallback is tried only when no primary is ready, whatever its number. Confirm, or rule a tie-break.
  **RULED 4 Oct (`docs/101` §11, 9.2): confirmed as built.**
- **Q2 Explicit selection vs priority.** Two legacy shapes select by name, not priority (`model_tiers.provider`,
  `content.research.provider`). Proposed: keep priority as the registry's rule and add an optional
  `preferProvider` on the request that only breaks a tie among ready connectors (the `preferAccountRef` precedent),
  never reaches a connector that is not ready. **RULED 4 Oct (`docs/101` §11, 9.2): approved narrowly; built in
  stream W6 as §2.4.**
- **Q3 The sixth and seventh shapes.** `ResearchService::PROVIDERS` and the `StorageDriver` binding are provider
  selection outside the five named in `docs/95` §10. Proposed: treat them as legacy shapes 6 and 7 with the steps
  above.
- **Q4 Surfacing.** Show the connector view in `hq:credentials:health` and on the Credentials screen as a second
  table, without changing the first table or the exit code. Not done in W3.
- **Q5 Credential kinds.** Once W2b's `CredentialKinds` merges, the registry can refuse a row whose credential kind
  is not in that list (one more load-time check). Proposed for the merge after W2b.
- **Q6 `erp.read` contract.** The catalogue names the final class `ErpConnector` because no interface exists; a
  second ERP adapter needs one extracted first (§5.5). **RULED 4 Oct (`docs/101` §11, 9.2): extract now. Done in
  stream W6 (§5.5a).**
