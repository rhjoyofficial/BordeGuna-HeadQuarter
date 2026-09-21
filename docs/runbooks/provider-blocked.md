# The model provider is blocked

Every AI call in HQ fails, or a gate stopped mid-run with a provider message. Several causes look
alike and are fixed by different people; the readiness table tells them apart (`docs/77` E.1). HQ
has two model providers, Anthropic and OpenAI, and exactly one serves at a time (ADR-049).

## Find out which

```
php artisan hq:ai:provider                   # "serving: anthropic" or "serving: openai"
php artisan hq:credentials:health --verify-model
```

`--verify-model` checks the provider that serves: one call of 16 output tokens. Read the `hq:hq` row
(its provider column says `anthropic_api_key` or `openai_api_key`), then the table for that provider.

## Anthropic (`anthropic_api_key`, account `hq`)

| State | The message you saw | Cause | Fix |
| --- | --- | --- | --- |
| `provider_blocked_by_credit` | 400, "Your credit balance is too low to access the Anthropic API" | the Console account behind HQ's key has no prepaid credit | Joy: console.anthropic.com, Settings, Billing, Buy credits (`docs/runbooks/anthropic-billing.md`) |
| `provider_blocked_by_credit` | 400, "You have reached your specified API usage limits. You will regain access on ..." | the organisation's own monthly spend limit is used up. It stays blocked until the date it names even with credit | Joy: Console, Settings, Limits, raise the monthly spend limit. That limit is the organisation's own; Claude Max never pays for API calls |
| `credential_invalid` | 401, "invalid x-api-key" | the key is wrong or was revoked | store a new key on the Credentials screen (provider `anthropic_api_key`, account `hq`) |
| `provider_unauthorized` | 403 | the key exists but lacks a permission | check the key's settings in the Console |
| `provider_unavailable` | 429, 5xx or 529 | rate limit or an outage | wait |
| `connector_error` | any other answer | an answer HQ could not use | read the message; it may be HQ's bug |

Until 21 September 2026 the usage-limit answer read as `connector_error` (and this page called it
`provider_unavailable`); it is a billing block and is now recorded as one (`docs/81` C.9).

## OpenAI (`openai_api_key`, account `hq`)

OpenAI answers throttling AND billing with the same 429; the error's code says which, never the
status alone (`docs/81` C.7.3). Retrying a billing block never restores access.

| State | The answer | Cause | Fix |
| --- | --- | --- | --- |
| `provider_blocked_by_credit` | 429 with `credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`, or type `insufficient_quota` | the account has no credit, or a spend or usage limit was reached. The key is fine | Joy: platform.openai.com, Billing: add credit or raise the limit (`docs/runbooks/openai-billing.md`) |
| `credential_invalid` | 401 | OpenAI does not accept the key (wrong, revoked, or from another organisation) | create a key in the Bor de Guna organisation's HQ project and store it again (provider `openai_api_key`, account `hq`; hard-reload the page first) |
| `provider_unauthorized` | 401 "IP not authorized", or 403 | a project IP allow-list, or an unsupported region: a setting, not the key | check the project's settings |
| `provider_unavailable` | any other 429, or 5xx | throttling or an outage | wait |
| `connector_error` | any other answer | an answer HQ could not use | read the message; it may be HQ's bug |

⚠ A key answers for the organisation and project it was created in, not for the account you are
looking at. On 21 September 2026 the stored key answered `credit_balance_exhausted` while the
Bor de Guna organisation's billing page showed credit. If the billing page shows credit and HQ still
reads blocked, check which organisation and project the stored key belongs to.

Claude Max and ChatGPT Plus (the subscriptions) never pay for API calls. HQ's own budget is a
separate ceiling and is read on `hq:ai:probe`.

## What happened to the gate

A provider failure during a gate marks each affected case **unmeasured**; the gate cannot pass and
the runner turns everything off again. The evidence stays (gate 11's first attempt: runs #24 and #25).
When the check answers 200 again, the same gate is relaunched once, under the same name, with no code
change in between.

## What never to do

Do not relaunch in a loop, do not tune the agent while the provider is down, do not switch anything
on by hand to "test". Fix the cause, read the table again, then one launch.
