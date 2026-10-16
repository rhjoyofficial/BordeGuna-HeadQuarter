-- row-hashes.sql (deploy/rehearsal, docs/128 section 16): a ROW-LEVEL snapshot, one line per table.
--
-- READ ONLY: a SELECT inside a READ ONLY transaction that is rolled back. Run with
--   psql -At -v ON_ERROR_STOP=1 -f row-hashes.sql
-- Output: `rows <table> <count> <md5 of every row's text, in a deterministic order>`. Two outputs are equal only
-- if every table holds exactly the same rows (any changed column of any row changes the hash). No row content
-- is printed, so the evidence carries no personal value.
BEGIN READ ONLY;
SET LOCAL statement_timeout = '300s';

SELECT 'rows ' || table_name || ' ' ||
       (xpath('/row/h/text()', query_to_xml(format(
           'SELECT count(*) || '' '' || md5(coalesce(string_agg(t::text, E''\n'' ORDER BY t::text), '''')) AS h FROM %I.%I t',
           table_schema, table_name), false, true, '')))[1]::text
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;

ROLLBACK;
