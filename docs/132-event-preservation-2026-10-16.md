# 132. Event preservation across containment, release and restore (16 October 2026)

Ruling `docs/131-chatgpt-ruling-2026-10-16.txt` section 1.1 (last paragraph), Stage B (last bullet), Stage D steps 4 and 10.

## Plan (written before the code)

1. Verify from code, as tests where possible: (a) a webhook is committed to `events` before the 202, with the scheduler stopped; (b) nothing but `hq:events:process` drains or expires `events` rows; (c) what a shop does with a 202 (assumption, shop code is not in this repo); (d) replay idempotency (dedupe key, facts upsert key).
2. A restore-safe bridge: `hq:events:export-since` (read only, JSONL with checksum) and `hq:events:import` (dry run by default; `--apply --by --reason` inserts absent rows as pending, never processes, audited, refuses conflicting payloads).
3. A drain pause flag `HQ_EVENTS_DRAIN_PAUSED`, default false, honoured by `hq:events:process`.
4. Wire the bridge into `deploy/rehearsal/rehearse.sh` and the documented restore procedure; no silent change to `deploy/restore.sh`.
5. Tests, gate, this record.
