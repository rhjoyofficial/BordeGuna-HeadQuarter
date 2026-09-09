# 55 - Credential replacement hardening (9 September 2026)

**Status: built, CI green, NOT deployed. Production is still `ae41291`, `ai.runtime` is still OFF,
no model has been called, and nothing in this change touches the model router, the Anthropic
driver, its headers, its authentication mode, its endpoint or the vault's decryption.**

This closes the one item `docs/54` recorded as "found, not fixed": storing a credential added a row
instead of replacing one, and the vault picked between rows without a rule.

| | |
| --- | --- |
| Branch | `phase2/credential-replacement-hardening` |
| Migration | 1, additive and reversible, refuses rather than repairs |
| New tests | 10 (`tests/Feature/Gateway/CredentialReplacementTest.php`) |
| Suite | 296 passed / 1451 assertions (was 286 / 1385) |
| Deployed | **no** |
| Safety gate | **not run.** Unchanged and still waiting on a working provider key |

---

## A. The defect, as it actually behaved

On 8 September the M4 live safety gate failed against four Anthropic keys in a row. Two of those
attempts were not key failures at all. Joy stored a replacement key, the screen said stored, the
audit log recorded `credential.stored`, and HQ went on authenticating with the key it had already
been told to stop using.

Two small things combined into one wrong answer.

`AdminController::storeCredential()` always called `create()`. There was no update path and no
replacement path, so a second key for the same provider and account did not take over from the
first: it sat beside it, equally active.

`CredentialVault::active()` then read `where('provider')->where('account_ref')->where('status',
'active')->first()` with no `ORDER BY`. A query with no ordering has no defined answer. PostgreSQL
was free to return either row and in practice returned the older one, which is the worst of the
possible behaviours: it looks exactly like a working system that disagrees with you.

The interesting part is not that it picked wrongly. It is that nothing in HQ was in a position to
notice. Every layer did its job on the row it was handed, and the row it was handed was chosen by a
query planner.

## B. What changed, in three layers

**The writer replaces.** `App\Support\Credentials\CredentialWriter` is now the one place that writes
an integration credential, as the vault is the one place that reads one. `replace()` runs inside a
transaction: it takes every active row for `(provider, account_ref)`, revokes each through the
lifecycle that already existed (status `revoked`, payload nulled), and only then creates the new
row. At no moment are two rows active for one identity. If it finds an identity that already had
two active rows, it revokes both rather than adding a third, so a replacement heals the state it
inherits.

Both writers go through it. The credentials screen calls it, and `OAuthManager::store()` calls it
too. That second one was a latent version of the same bug: it keyed `updateOrCreate` on provider,
account_ref, business_id and brand_id, which is not the identity the vault resolves by, so
reconnecting the same account under a different business would have produced a second active row
for one identity.

**The database refuses.** A partial unique index, `integration_credentials_one_active_per_identity`,
on `(provider, coalesce(account_ref, ''))` where `status = 'active' and deleted_at is null`. The
`coalesce` is not decoration: PostgreSQL treats NULLs in a unique index as distinct, so without it
two rows with no account reference would both be allowed. The `deleted_at` clause makes the index
describe exactly the set the vault queries, since the model soft-deletes. Application code is a
promise; an index is a guarantee.

**The vault refuses to guess.** `active()` now orders by id, reads at most two rows, and throws
`AmbiguousCredential` when a second one exists, naming the provider, the account and the row ids and
nothing else. `CredentialResolver` catches it on the gateway path and turns it into a named
missing-credential reason, so a tool call refuses cleanly as `Unavailable` with the ids written down
rather than crashing. The resolver also stopped running its own copy of the lookup: it asks the
vault, so the id recorded on `tool_calls` cannot disagree with the credential the vault later opens.

## C. Four decisions worth defending

**A replacement still produces a NEW id and a NEW `credential.stored` record.** Updating the row in
place would have been the smaller change and it would have destroyed the only diagnostic an operator
has. A stale credentials tab expires to a 419 that looks exactly like a successful submit. Twice on
8 September the answer to "did the key arrive" came from "same id, no new `credential.stored`", and
that answer stays available.

**Ambiguity refuses; it is not resolved by "newest wins".** Newest-wins would have fixed the 8
September incident and would be wrong in general, because the rows do not record which key the
operator meant to keep. Two active credentials for one identity is an operational error. HQ says so
and stops. That is also why the refusal is a plain `RuntimeException` rather than one of the commerce
exceptions: `FailureClassifier` maps an unclassified throwable to `Internal`, which is what this is,
a defect in HQ's own data rather than the far side saying no.

**Nothing is deleted.** Superseded rows keep their id, their history and their audit records, and
only lose their payload. A revoked row is the record that a key was in use until a moment, which is
exactly the question an incident asks later.

**The migration refuses rather than repairs.** If a database already holds duplicate active rows,
`up()` throws with the offending identities named and changes nothing. Choosing which of two keys to
keep is not a decision a migration is entitled to make silently: it is the same class of defect the
index exists to prevent. The check runs before any DDL, because a failed statement inside a
PostgreSQL migration transaction poisons the transaction and turns the COMMIT into a silent
ROLLBACK while the migration is still recorded as run.

## D. What did not change

The Anthropic driver, its headers, its authentication mode and its endpoint. The vault's decryption
path and the `encrypted:array` cast. The model router, the tier table, the cost accounting and every
M4 safety-gate semantic. `ai.runtime` is untouched and still off by an explicit `enabled=false` row.
No agent, no agent-executable tool, no R4 or R5 tool enabled, no customer-facing AI, no autonomous
execution. The bypass test and the safety gate have not been run.

## E. Evidence

The suite is 296 passed / 1451 assertions, up from 286 / 1385, with Pint clean, `tsc --noEmit`
clean and the Vite build clean. Migrations were exercised the way CI exercises them, `migrate:fresh`
then `migrate:rollback` then `migrate:fresh --seed`, against PostgreSQL 17.

The migration's refusal path was exercised deliberately rather than assumed. Two duplicate active
pairs were planted, one with an account reference and one with a NULL account reference, the
migration row was removed, and `migrate` was re-run: it named both identities, changed no row, and
applied cleanly once the extras were revoked. The NULL case is the one that proves the `coalesce`.

The ten new tests cover first creation, replacement, exactly one active row surviving, the old row
retiring through the existing lifecycle, healing an identity that already had duplicates, the
database refusing a second active row, deterministic refusal repeated three times on the same
ambiguous state, a tool call refusing with the ids named and no secret anywhere in `tool_calls` or
`audit_logs`, the credentials screen itself replacing rather than accumulating, and a neighbouring
provider and account being left alone. The existing `CredentialVaultTest` boundary tests pass
unchanged, including the structural test that greps the codebase to prove the vault is still the
only place that reads a payload.

## F. Before this is deployed

Run the duplicate check on production first, because the migration will refuse the release if it
finds any:

```sql
select provider, coalesce(account_ref, '') as account_ref, count(*)
from integration_credentials
where status = 'active' and deleted_at is null
group by 1, 2 having count(*) > 1;
```

Zero rows means the migration will apply. Rows mean the extras must be revoked on the credentials
screen first, and the safety-gate diagnosis should be re-read in that light.

Once it is deployed, the "revoke the old credential before adding a new one" procedure recorded in
`docs/54` is retired. Adding a credential revokes what it replaces, in one transaction, and says so
in the audit log.

## G. Merged, and the deployment plan (9 September, not yet executed)

PR #7 was approved and merged. **`main` is now `01c8031`** (merge commit), carrying `c751b0e` and
`fbe509b`. CI on the branch was green on both jobs: the suite at 296 / 1451, and the production
image build after a re-run. The first image build attempt failed on `pecl.php.net` answering HTTP
504 for `redis-6.3.0.tgz`, which is upstream and unrelated to this change; it matters only because
`deploy.sh` builds the same image on the VPS and could hit the same outage.

**Production remains `ae41291` and is not deployed.**

### The pre-flight, run on production on 9 September

The section F check returned **zero rows**. The table holds 13 credentials, 8 of them active, every
one on a distinct `(provider, account_ref)`: `erp_api_token/bor-de-guna`, `shop_admin_token` and
`shop_assistant_key` and `shop_webhook_secret` for each of `laladia-com` and `bionic-garden`, and
`anthropic_api_key/hq` at id 13. The three earlier Anthropic rows (10, 11, 12) are revoked and
soft-deleted, and two old `shop_assistant_key` rows with no account reference (2 and 3) are revoked
and soft-deleted as well. Those last two are the case the `coalesce` in the index exists for; they
are retired, so they do not conflict. No `integration_credentials_one_active_per_identity` index is
present yet, as expected. Seven containers are up and healthy.

**The migration will apply.** Nothing needs repairing first.

⛔ **One live interaction to watch.** Production still runs the OLD code, where storing a credential
adds a row instead of replacing one. If a new Anthropic key is stored on production before this
release goes out, and the existing id 13 is not revoked first, that alone creates the duplicate
state which makes this migration refuse. **Until this is deployed, revoke before adding**, and if a
key is added in the meantime, re-run the section F check before deploying.

### The release, when it is authorised

The normal backup-first path, nothing bespoke. From `/opt/bordeguna-hq`: confirm `deploy/.env` is
still mode 600, re-run the section F check because production state can move between now and then,
then `./deploy/deploy.sh`. That takes the pre-deploy dump first and refuses to deploy if the dump
fails, builds the `app` and `backup` images, brings the stack up, runs the migration in the app
entrypoint as batch 8, terminates Horizon gracefully, recreates `web` so nginx re-resolves the app
container, smoke-checks `/up` and `/api/health`, and appends the release to `deploy/RELEASES.md`.

If the image build hits the pecl outage again it fails at the build step, before anything is
brought up, and the running stack is untouched. That is a safe failure.

### What to verify afterwards

That the index exists, and that the eight active credentials are still eight. Then the six-job
regression, as after M4: this release changes `CredentialResolver`, which is on the hot path of
every scheduled job that uses a credential, so the jobs should be seen succeeding through the
gateway with zero denials rather than assumed to be fine. Then confirm the safety posture has not
moved: `ai.runtime` still off, `ai_runs` still three failed rows, no agent, no agent-executable
tool.

### Rollback

`./deploy/rollback.sh ae41291`, which is code-only by design.

⚠ **The index survives a code rollback**, because rollback deliberately does not run migrations.
Old code plus new index means storing a credential for an identity that already has an active one
raises a unique violation instead of silently duplicating: a loud 500 on the credentials screen
rather than a quiet wrong answer. If that is in the way during an incident, revoke the old row
first, or drop the index by hand with
`DROP INDEX integration_credentials_one_active_per_identity;`.
