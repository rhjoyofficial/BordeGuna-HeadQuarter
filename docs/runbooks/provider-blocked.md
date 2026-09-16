# The model provider is blocked

Every AI call in HQ fails, or a gate stopped mid-run with a provider message. Five causes look alike
and are fixed by different people; the readiness table tells them apart (`docs/77` E.1).

## Find out which

```
php artisan hq:credentials:health --verify-model
```

Read the `hq:hq` row:

| State | The message you saw | Cause | Fix |
| --- | --- | --- | --- |
| `provider_blocked_by_credit` | "Your credit balance is too low to access the Anthropic API" | the Console account behind HQ's key has no prepaid credit | Joy: console.anthropic.com, Settings, Billing, Buy credits. A gate costs about $2.60; the shadow week $3 to $8 |
| `credential_invalid` | 401, "invalid x-api-key" | the key is wrong or was revoked | store a new key on the Credentials screen (provider `anthropic_api_key`, account `hq`) |
| `provider_unauthorized` | 403 | the key exists but lacks a permission | check the key's settings in the Console |
| `provider_unavailable` | 429 or 5xx or 529, or "specified API usage limits" | rate limit, the Console's monthly spend limit, or an outage | wait, or raise the monthly limit in the Console (that limit is the organisation's own, not Claude Max) |
| `connector_error` | anything else | an answer HQ could not use | read the message; it may be HQ's bug |

Claude Max (the subscription) never pays for API calls. HQ's own $50 budget is a third, separate
ceiling and is read on `hq:ai:probe`.

## What happened to the gate

A provider failure during a gate marks each affected case **unmeasured**; the gate cannot pass and
the runner turns everything off again. The evidence stays (gate 11's first attempt: runs #24 and #25).
When the check answers 200 again, the same gate is relaunched once, under the same name, with no code
change in between.

## What never to do

Do not relaunch in a loop, do not tune the agent while the provider is down, do not switch anything
on by hand to "test". Fix the cause, read the table again, then one launch.
