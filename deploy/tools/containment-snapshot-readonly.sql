-- containment-snapshot-readonly.sql (docs/132 section 5A, docs/134 section 5 step 2)
-- READ ONLY. Run right after `stop scheduler`: records the event backlog at the moment of containment.
-- Prints counts, ids, types and times only; never a payload.
BEGIN READ ONLY;
SET LOCAL statement_timeout = '30s';
SELECT now() AS snapshot_at;
SELECT status, count(*) AS n FROM events GROUP BY status ORDER BY status;
SELECT event_id, type, created_at FROM events WHERE status = 'processing' ORDER BY created_at;
SELECT max(id) AS max_event_id FROM events;
SELECT type, count(*) AS pending FROM events WHERE status = 'pending' GROUP BY type ORDER BY type;
ROLLBACK;
