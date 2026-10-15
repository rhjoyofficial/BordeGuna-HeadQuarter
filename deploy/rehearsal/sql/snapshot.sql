-- snapshot.sql (deploy/rehearsal, docs/128 D.1): what the database holds, as text a diff can compare.
--
-- READ ONLY: every statement is a SELECT inside a READ ONLY transaction that is rolled back. Run with
--   psql -At -v ON_ERROR_STOP=1 -f snapshot.sql
-- Output, one fact per line, deterministic order:
--   count <table> <rows>          one line per table in schema public
--   migration <name>              one line per row of the migrations table
--   constraint <table> <name> <definition>   for the tables the release touches
--   index <table> <name> <definition>
--   trigger <table> <name>
--   column <table> <name> <type> <nullable>
BEGIN READ ONLY;
SET LOCAL statement_timeout = '120s';

SELECT 'count ' || table_name || ' ' ||
       (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', table_schema, table_name), false, true, '')))[1]::text
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;

SELECT 'migration ' || migration FROM migrations ORDER BY migration;

SELECT 'constraint ' || conrelid::regclass::text || ' ' || conname || ' ' || pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conrelid::regclass::text IN ('customers', 'stores', 'customer_identities', 'customer_identity_conflicts',
    'consent_events', 'consent_state', 'outcomes', 'outcome_result_refs', 'outcome_attributions',
    'playbook_versions', 'playbook_version_events', 'opportunities', 'follow_ups',
    'cross_business_quarantines', 'cross_business_quarantine_reviews', 'decision_records',
    'evaluation_runs', 'evaluation_results', 'agent_runs')
ORDER BY 1;

SELECT 'index ' || tablename || ' ' || indexname || ' ' || indexdef
FROM pg_indexes
WHERE schemaname = 'public' AND tablename IN ('customers', 'stores', 'customer_identities', 'customer_identity_conflicts',
    'consent_events', 'consent_state', 'outcomes', 'outcome_result_refs', 'outcome_attributions',
    'playbook_versions', 'playbook_version_events', 'opportunities', 'follow_ups',
    'cross_business_quarantines', 'cross_business_quarantine_reviews', 'decision_records',
    'evaluation_runs', 'evaluation_results', 'agent_runs')
ORDER BY 1;

SELECT 'trigger ' || event_object_table || ' ' || trigger_name
FROM information_schema.triggers
WHERE trigger_schema = 'public'
GROUP BY event_object_table, trigger_name
ORDER BY 1;

SELECT 'column ' || table_name || ' ' || column_name || ' ' || data_type || ' ' || is_nullable
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name IN ('customer_identities', 'customer_identity_conflicts', 'consent_events', 'consent_state',
    'decision_records', 'evaluation_runs', 'evaluation_results', 'agent_runs', 'outcome_attributions')
ORDER BY 1;

ROLLBACK;
