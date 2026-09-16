# Readiness and credentials

Whether HQ could reach each store, ERP business and the model provider right now, and what each
state means (ADR-043, `docs/74` C.3, `docs/77` E.1). Never a secret value.

```
php artisan hq:credentials:health                 # the table, from what HQ already knows
php artisan hq:credentials:health --verify        # also probes each shop and the ERP, read-only, and records the answers
php artisan hq:credentials:health --verify-model  # also sends the model provider its 8-token check (a fraction of a cent)
```

The same table is on the Credentials screen. Its exit code is non-zero while any supported target
is an incident, which is the check an activation runbook must pass.

## The two columns that matter

- **Would attempt**: nothing structural stops HQ from sending the call (a credential with a value,
  the switch on).
- **Verified**: the provider accepted the credential the last time it was asked.

A row can be "would attempt: yes, verified: no". That is exactly the row to look at.

## The states

| State | Meaning | What to do |
| --- | --- | --- |
| `verified` | accepted on the last check or live call | nothing |
| `credential_unverified` | stored, never yet accepted or rejected | run `--verify` (or `--verify-model`) |
| `credential_missing` | no active row with a value | store one on the Credentials screen |
| `credential_invalid` | expired, or rejected on authentication (401) | store a new one; the gateway refuses to use this one |
| `provider_blocked_by_credit` | the provider refused because the account has no credit; the key is not judged | add credit to the provider account (see [provider-blocked](provider-blocked.md)) |
| `provider_unauthorized` | the provider refused a permission the key lacks (403); not condemned | check what the key is allowed to do |
| `provider_unavailable` | the last check found the provider down, slow or unreachable; says nothing about the key | try again later |
| `connector_error` | the last answer was one HQ could not use | read the message; a bug on either side |
| `unsupported` | HQ has no connector for the target (Econic WooCommerce, the ERP counter); by design, never an incident | nothing; the shops reach HQ through the ERP once its modules exist |
| `disabled` | the tool switch is off for the business | the Tools screen |

## Replacing a token

Storing replaces: whatever was active for the same provider and account is revoked in the same
transaction. Shop tokens are non-expiring service tokens created in the shop's own app root
(LaLaDia: its cPanel app; Bionic: `~/bionic-garden-app`, not the deleted `~/bionicProject`) for the
service user `hq@bordeguna.com`, verified by the shop itself before being stored. Never paste a value
into chat; the Credentials screen is the only place it goes.
