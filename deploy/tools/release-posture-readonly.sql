-- release-posture-readonly.sql (docs/128; ruling docs/120 D.3 and D.4): the posture a release must leave untouched.
--
-- READ ONLY, three ways: every statement is a SELECT; the file runs inside BEGIN READ ONLY ... ROLLBACK, so
-- PostgreSQL refuses any write; and the checklists run it with PGOPTIONS='-c default_transaction_read_only=on'.
-- Runs on BOTH schemas (production b96a396 and the candidate): it names no column the release adds, except in
-- the last block, which checks first whether the column exists. Counts and keys only; no customer data.
--
--   docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T -e PGOPTIONS='-c default_transaction_read_only=on' \
--     db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/tools/release-posture-readonly.sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = '60s';

SELECT 'release_db ' || current_database() || ' read_only=' || current_setting('transaction_read_only');
SELECT 'migrations ' || count(*) || ' last=' || max(migration) FROM migrations;

-- Flags: ai.runtime, channels.meta.inbound, channels.meta.forwarded must be false (or absent: absent reads as off).
SELECT 'flag ' || key || ' ' || coalesce(environment, '*') || ' ' || enabled FROM feature_flags ORDER BY key, environment;
SELECT 'flags_on_that_must_be_off ' || count(*) FROM feature_flags
WHERE enabled AND key IN ('ai.runtime', 'channels.meta.inbound', 'channels.meta.forwarded');
-- The obsolete scheduler.gateway row (docs/116): present until Joy removes it AFTER the deployed code is verified
-- not to read it (structural search S2 --container). The release itself never removes it.
SELECT 'scheduler_gateway_row ' || count(*) FROM feature_flags WHERE key = 'scheduler.gateway';

-- Agents paused at A0, zero active grants, no open shadow period.
SELECT 'agent ' || key || ' status=' || coalesce(status, 'null') || ' max_autonomy=' || coalesce(max_autonomy_level, 'null') FROM agents ORDER BY key;
SELECT 'agents_not_paused_or_above_a0 ' || count(*) FROM agents WHERE coalesce(status, '') <> 'paused' OR lower(coalesce(max_autonomy_level, 'a0')) <> 'a0';
SELECT 'autonomy_grants_active ' || count(*) FROM autonomy_grants WHERE status = 'active';
SELECT 'shadow_periods_open ' || count(*) FROM shadow_periods WHERE status = 'open';

-- Scheduler: jobs by state (the two paused jobs of docs/121 section 5 stay paused), effectful tools by switch.
SELECT 'scheduled_jobs state=' || coalesce(state, 'null') || ' ' || count(*) FROM scheduled_jobs GROUP BY state ORDER BY state;
SELECT 'tools_disabled ' || count(*) || ' of ' || (SELECT count(*) FROM tools) FROM tools WHERE NOT enabled;
SELECT 'approvals_pending ' || count(*) FROM approvals WHERE status = 'pending';

-- Consent: the ledger and its projection, counted by state. A release writes no consent row; compare before/after.
SELECT 'consent_events state=' || state || ' ' || count(*) FROM consent_events GROUP BY state ORDER BY state;
SELECT 'consent_state state=' || state || ' ' || count(*) FROM consent_state GROUP BY state ORDER BY state;
-- The projection never says more than the ledger: a projection row whose state differs from its last event.
SELECT 'consent_projection_drift ' || count(*) FROM consent_state s JOIN consent_events e ON e.id = s.last_event_id WHERE e.state <> s.state;

-- Cost coverage: evaluation runs and how many carry a cost (the attribution columns arrive with the release).
SELECT 'evaluation_runs ' || count(*) || ' cost_usd_zero=' || count(*) FILTER (WHERE coalesce(cost_usd, 0) = 0) FROM evaluation_runs;
SELECT 'ai_runs ' || count(*) FROM ai_runs;

-- Attributed vs unattributed evaluation cost. The column arrives with the release, so the query runs only when it
-- exists (the pre-release schema prints `column_absent`); query_to_xml keeps it one read-only SELECT.
SELECT 'evaluation_cost_attribution ' || CASE WHEN EXISTS (
        SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'evaluation_runs' AND column_name = 'cost_attribution')
    THEN (xpath('/row/t/text()', query_to_xml('SELECT ''attributed='' || count(*) FILTER (WHERE cost_attribution IS NOT NULL) || '' unattributed='' || count(*) FILTER (WHERE cost_attribution IS NULL) AS t FROM evaluation_runs', false, true, '')))[1]::text
    ELSE 'column_absent (pre-release schema)' END;

ROLLBACK;
