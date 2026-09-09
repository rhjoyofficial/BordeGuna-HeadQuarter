# 58 - Phase 2.5 production deployment (9 September 2026)

**Status: DEPLOYED. Production is `db3430a` (Phase 2.5 code = `acf9e1e`, plus one docs commit),
released 10:57:36 UTC as migration batch 9. The safety corpus is seeded: 1 suite, 12 cases. `ai.runtime`
is still OFF, no agent exists, no autonomy grant exists, no consent event exists, and nothing customer-
facing or externally reachable was enabled.**

This is the deployment record for `docs/57`. It is kept separate from the build record, as `docs/54`
and `docs/55` were kept separate from `docs/52`, because a deployment finds things a build cannot,
and this one did.

| | |
| --- | --- |
| Released | `db3430a`, 10:57:36 UTC, previous `2784663` |
| Migrations | **6** (docs/57 said five; the count in that table was wrong, the list was right), batch 9 |
| Pre-deploy dumps | `hq-20260909-105410.dump` and `hq-20260909-105448.dump`, 365,303 bytes, 45 tables, both verified |
| Seeded | `Database\Seeders\SafetyCorpusSeeder`, 1 suite + 12 cases, audit log untouched |
| Rollback | `./deploy/rollback.sh 2784663`, code-only; the twelve tables and the corpus survive, inert |

---

## A. The audit before anything moved

The session before this one ended with a power cut, so the first act was to establish where things
stood rather than trust the last note. Local `main` was clean and equal to `origin/main` at `db3430a`,
one docs-only commit past the merge. CI on `acf9e1e` was green (run 34329868956, 367 / 1764). On the
VPS, production was still `2784663`, the last migration batch 8, all seven headline tables and both
new columns absent, `ai.runtime` false with `scheduler.gateway` the only other flag, 8 active
credentials one per identity with the coalesce index present, 17 customers and none with a `consent`
blob, 0 failed queue jobs, 7 containers healthy, 74 GB free. In other words exactly the state
`docs/57` §N recorded, and nothing half-applied. Joy authorised the deploy and, separately, the
seeder.

## B. The deploy refused itself, and why that was correct

`./deploy/deploy.sh` fast-forwarded the checkout to `db3430a`, started the pre-deploy dump, and
stopped: the backup container could not create `/backups/daily`, `pg_dump` had nowhere to write,
and the script refused to go further. That refusal is the design from `docs/28` doing its job.

The cause was not the release. The backup sidecar bind-mounts `/root/backups/bordeguna-hq` from
the host, and that directory no longer existed. At 08:17 UTC a separate session had taken a
"fresh full backup from live data, replacing all earlier backups" into `/root/backups/current-20260909`
(one HQ dump, one ERP dump, two n8n archives, a manifest with checksums), and at 09:08:53 UTC, one
second after an SSH login from the same address every session here uses, the older directories were
removed. The bind mount inside the still-running container kept pointing at the deleted inode, which
is why `mkdir` inside it returned "No such file or directory" instead of quietly recreating a path.

Consequences worth stating plainly:

- **HQ's own backup history is gone.** Every daily and weekly dump the sidecar had made since
  5 September was in that directory. What survives of HQ before this release is the single 08:17
  snapshot in `current-20260909` (206,818 bytes, `pg_restore --list` shows 45 table-data entries
  including `ai_runs` and `integration_credentials`), and it was taken by a different tool than the
  sidecar, so it carries none of the sidecar's marker files.
- The nightly n8n automation directory `/root/backups/automation` went with it; its 02:00 job
  had reported 15 snapshots kept that morning.
- The removal was deliberate and documented in its own manifest. It was not an accident and not
  the power cut. It was, however, invisible to the HQ record and to the sidecar, which had been
  running against a dead mount for nearly two hours by the time the deploy asked it for a dump.

## C. The repair

The compose file declares the path, so the repair was to give it back: recreate
`/root/backups/bordeguna-hq/daily` and `/weekly`, recreate the stateless `backup` container so its
mount attached to the new directory (`docker inspect` confirmed the source), and run
`/backup.sh once`. It wrote `hq-20260909-105410.dump`, 365,303 bytes, 45 tables, verified, visible
on the host, `LAST_STATUS` 0. Then the deploy was re-run with `--no-pull`, since the checkout was
already at `db3430a`, and it dumped again (`-105448`, identical size) before building.

## D. The release

The build was slow because `--pull` refreshed the PHP base image and the intl extension recompiled
from source; nothing about that is specific to this release. The app entrypoint ran the six
migrations as batch 9 in under 300 ms combined. Horizon terminated gracefully, `web` was recreated,
`/up` answered, and `/api/health` reported ok on application, database, redis, queue (1 supervisor)
and scheduler (tick age 35 s). Seven containers healthy.

One wrinkle for the next reader of `deploy/RELEASES.md`: the new row says "previous db3430a",
because `deploy.sh` records `PREV` from the checkout and the refused first run had already
fast-forwarded it. The true previous release is `2784663`. See §H.

## E. What the schema check found

Batch 9 holds six rows, the six Phase 2.5 files. Twelve tables present and every one empty:
`consent_events`, `consent_state`, `autonomy_grants`, `evaluation_suites`, `evaluation_cases`,
`evaluation_runs`, `evaluation_results`, `media_assets`, `conversations`, `conversation_messages`,
`conversation_participants`, `memories`. Both `platform_accounts` columns present. The three partial
unique indexes present by name in `pg_indexes` (`consent_state_identity_unique`,
`conversations_channel_thread_unique`, `memories_structured_key_unique`; as `docs/55` noted, partial
indexes are not in `pg_constraint`). Both `consent_events` triggers present (`_no_update`,
`_no_delete`), and the two `audit_logs` triggers still there. Flags unchanged. Posture unchanged:
`ai_runs` 4 with 1 succeeded, agents 0, approvals 12, 8 active credentials, 0 duplicate identities,
0 failed jobs. `hq:tools:sync --list` shows seven tools, agent "not yet" on all of them, and
`demo.request_approval` still DISABLED.

## F. The six-job regression

Run through `ScheduledJobService::runNow()` from tinker so the real queue path carried them, as in
`docs/55`. Job runs **52 to 57, all six succeeded on attempt 1**, trigger `run_now`, no error, each
in under a second. Tool calls **50 to 55, all `policy=allow`, all `succeeded`, `caller_kind=scheduler`**,
and the scopes still come from the payload: LaLaDia business 2 / store 1, Bionic business 3 /
store 2, both ERP polls business 1, the no-op with `actor_type=human` and `enabling_user_id` 1 as
before. Connector calls **74 to 77, all `succeeded`**: `shop:laladia-com` listRecentOrders,
`erp:bor-de-guna` lowStock and overview, `shop:bionic-garden` listRecentOrders. Zero failed queue
jobs. And the point of running it at all: the consent gate is now on every one of those hot paths
as step 5b, and after six passes `consent_events`, `autonomy_grants` and `conversations` are all
still zero. It returned `notRequired()` six times and wrote nothing, which is what `docs/57` said it
would do and is now what production says too.

## G. The corpus

`Database\Seeders\SafetyCorpusSeeder` by full namespace with `--force`. Before: suites 0, cases 0,
`audit_logs` max id 311. After: **suites 1, cases 12**, `audit_logs` max id still 311, and every
other new table still 0, `ai_runs` still 4, `tool_calls` still 55. The twelve cases, all severity
`blocker`, all graded deterministically:

`injection.customer_message_instructs_tool_call`, `injection.web_page_instructs_exfiltration`,
`injection.delayed_via_memory`, `credential.direct_request`, `credential.indirect_via_debug`,
`scope.cross_business_read`, `scope.cross_business_via_helpfulness`, `sql.direct_query_request`,
`sql.shell_request`, `bypass.claimed_prior_approval`, `bypass.urgency_pressure`,
`bypass.autonomy_self_grant`.

Nothing runs them yet. `RubricGrader` refuses while `ai.runtime` is off and no evaluation run has
been created; `evaluation_runs` is 0.

## H. Found, and carried forward rather than fixed here

1. **`deploy.sh` fast-forwards before it dumps.** If the dump fails, the script exits correctly,
   but the checkout is already at the new commit while the containers still run the old one, and
   the next `RELEASES.md` row misreports its predecessor. Moving `git merge --ff-only` after the
   dump would fix both. It is a one-line change to a script that only takes effect on the next
   pull, and it is left for Joy to approve rather than slipped into a docs commit.
2. **HQ's backup history is a single snapshot plus today's two dumps.** The sidecar is running
   again and will resume at 01:00 UTC, but the 14-daily / 8-weekly retention starts from zero. The
   `docs/28` local-only design already said this disk is not disaster-proof; today showed it is not
   consolidation-proof either. Any future "replace all earlier backups" step on this VPS must
   recreate the sidecar's directory, or restart the sidecar, or better, leave that directory alone.
   The same applies to `/root/backups/automation`.
3. **`docs/57` counted five migrations; there are six.** The list in §N was complete, the number in
   the header table was not. Corrected in `docs/57` alongside this record.
4. **`bordeguna-erp-worker-1` restarts about once an hour**, per `hq-monitor.log` (alerts at 05:40,
   06:40, 07:55, 08:55, 09:55, 10:55 today). That is the ERP stack, not HQ, and it predates this
   release by days. Recorded here because the monitor lives in this repo; it is not investigated
   here.

## I. What is still closed

Phase 3, M5 agents, Messenger/WhatsApp, ads automation and the Research Engine are not started and
not touched. `ai.runtime` is off with its row kept. Nothing at R4 or above is enabled. No tool is
agent-executable. No agent, autonomy grant, conversation, memory, media asset or consent event
exists. The next milestone is Joy's to name.
