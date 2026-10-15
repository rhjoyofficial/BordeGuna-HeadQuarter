-- verify-constraints.sql (deploy/rehearsal, docs/128 D.1 and D.4): the constraints the release must leave in place.
--
-- READ ONLY (catalogue SELECTs in a READ ONLY transaction, rolled back). One line per check, `PASS <name>` or
-- `FAIL <name>`; the caller greps for FAIL. Run with  psql -At -v ON_ERROR_STOP=1 -f verify-constraints.sql
-- Each check names the ruling it serves (docs/120 A2 for the identity keys, docs/107 D16 for the attribution
-- key, docs/114 for consent scope, docs/117 and docs/112 for the append-only triggers).
BEGIN READ ONLY;
SET LOCAL statement_timeout = '60s';

-- A2 item 3: the business-scoped unique key, NULLS NOT DISTINCT, over live rows only.
SELECT CASE WHEN EXISTS (
    SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'customer_identities_business_key'
      AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%(business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT%'
      AND indexdef LIKE '%WHERE (quarantined_at IS NULL)%'
) THEN 'PASS' ELSE 'FAIL' END || ' customer_identities_business_key unique NULLS NOT DISTINCT over live rows';

-- A2 item 2: the composite foreign key (customer_id, business_id) -> customers (id, business_id).
SELECT CASE WHEN EXISTS (
    SELECT 1 FROM pg_constraint WHERE conrelid = 'customer_identities'::regclass AND conname = 'customer_identities_customer_business_foreign'
      AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (customer_id, business_id) REFERENCES customers(id, business_id)%'
) THEN 'PASS' ELSE 'FAIL' END || ' customer_identities_customer_business_foreign composite FK to customers';

-- A2 item 4: a live store belongs to the same business.
SELECT CASE WHEN EXISTS (
    SELECT 1 FROM pg_constraint WHERE conrelid = 'customer_identities'::regclass AND conname = 'customer_identities_store_business_foreign'
      AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (live_store_id, business_id) REFERENCES stores(id, business_id)%'
) THEN 'PASS' ELSE 'FAIL' END || ' customer_identities_store_business_foreign composite FK to stores';

-- A2 item 2: the unique keys the composite FKs rest on.
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'customers'::regclass AND conname = 'customers_id_business_unique' AND contype = 'u')
    THEN 'PASS' ELSE 'FAIL' END || ' customers_id_business_unique';
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'stores'::regclass AND conname = 'stores_id_business_unique' AND contype = 'u')
    THEN 'PASS' ELSE 'FAIL' END || ' stores_id_business_unique';

-- A2 item 1: business_id is NOT NULL; the old group-wide unique key is gone.
SELECT CASE WHEN (SELECT is_nullable FROM information_schema.columns WHERE table_name = 'customer_identities' AND column_name = 'business_id') = 'NO'
    THEN 'PASS' ELSE 'FAIL' END || ' customer_identities.business_id NOT NULL';
SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'customer_identities'::regclass AND conname = 'customer_identities_channel_unique')
    THEN 'PASS' ELSE 'FAIL' END || ' old group-wide unique key customer_identities_channel_unique removed';
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'customer_identities'::regclass AND conname = 'customer_identities_quarantine_check')
    THEN 'PASS' ELSE 'FAIL' END || ' customer_identities_quarantine_check';

-- The conflicts table and its open-row key.
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'customer_identity_conflicts_open_key' AND indexdef LIKE '%NULLS NOT DISTINCT%' AND indexdef LIKE '%WHERE (resolved_at IS NULL)%')
    THEN 'PASS' ELSE 'FAIL' END || ' customer_identity_conflicts_open_key';

-- Data holds the keys: no live row violates them (the index would refuse; this proves no row escaped it).
SELECT CASE WHEN (SELECT count(*) FROM (
        SELECT 1 FROM customer_identities WHERE quarantined_at IS NULL
        GROUP BY business_id, channel, external_id, source_store_id HAVING count(*) > 1) d) = 0
    THEN 'PASS' ELSE 'FAIL' END || ' no duplicate live identity key';
SELECT CASE WHEN (SELECT count(*) FROM customer_identities i JOIN customers c ON c.id = i.customer_id WHERE c.business_id <> i.business_id) = 0
    THEN 'PASS' ELSE 'FAIL' END || ' every identity carries its owner''s business';
SELECT CASE WHEN (SELECT count(*) FROM customer_identities i JOIN stores s ON s.id = i.source_store_id WHERE i.quarantined_at IS NULL AND s.business_id <> i.business_id) = 0
    THEN 'PASS' ELSE 'FAIL' END || ' no live identity names a store of another business';
SELECT CASE WHEN (SELECT count(*) FROM customer_identities WHERE quarantined_at IS NOT NULL) = (SELECT count(*) FROM customer_identity_conflicts WHERE kind = 'quarantined' AND resolved_at IS NULL) OR
                 (SELECT count(*) FROM customer_identities WHERE quarantined_at IS NOT NULL) <= (SELECT count(*) FROM customer_identity_conflicts WHERE kind = 'quarantined')
    THEN 'PASS' ELSE 'FAIL' END || ' every quarantined identity has a conflict row';

-- docs/107 D16: an attribution's business is its outcome's, at the database.
SELECT CASE WHEN EXISTS (
    SELECT 1 FROM pg_constraint WHERE conrelid = 'outcome_attributions'::regclass AND contype = 'f'
      AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (outcome_id, business_id) REFERENCES outcomes(id, business_id)%'
) THEN 'PASS' ELSE 'FAIL' END || ' outcome_attributions composite FK (outcome_id, business_id) -> outcomes';
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'outcome_attributions'::regclass AND conname = 'outcome_attributions_typed_action' AND pg_get_constraintdef(oid) LIKE '%follow_up_id%')
    THEN 'PASS' ELSE 'FAIL' END || ' outcome_attributions_typed_action covers follow_up_id (docs/123)';

-- docs/114: consent scope unique index carries brand and identity.
SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'consent_state_identity_unique' AND indexdef LIKE '%brand_id%' AND indexdef LIKE '%identity_id%')
    THEN 'PASS' ELSE 'FAIL' END || ' consent_state_identity_unique scoped by brand and identity';

-- Append-only ledgers refuse UPDATE and DELETE at the database.
SELECT CASE WHEN (SELECT count(DISTINCT trigger_name) FROM information_schema.triggers WHERE event_object_table IN ('outcomes', 'outcome_result_refs', 'outcome_attributions')) = 6
    THEN 'PASS' ELSE 'FAIL' END || ' outcome ledger immutability triggers (6)';
SELECT CASE WHEN (SELECT count(DISTINCT trigger_name) FROM information_schema.triggers WHERE event_object_table IN ('cross_business_quarantines', 'cross_business_quarantine_reviews')) = 4
    THEN 'PASS' ELSE 'FAIL' END || ' quarantine append-only triggers (4)';
SELECT CASE WHEN (SELECT count(DISTINCT trigger_name) FROM information_schema.triggers WHERE event_object_table IN ('playbook_versions', 'playbook_version_events')) = 3
    THEN 'PASS' ELSE 'FAIL' END || ' playbook guard and append-only triggers (3)';

-- Every FK of the new tables is RESTRICT or (on derived projections only) CASCADE: none is SET NULL on a ledger.
SELECT CASE WHEN (SELECT count(*) FROM pg_constraint WHERE contype = 'f' AND confdeltype IN ('n', 'c')
        AND conrelid::regclass::text IN ('outcomes', 'outcome_result_refs', 'outcome_attributions', 'opportunities', 'follow_ups',
            'cross_business_quarantines', 'cross_business_quarantine_reviews', 'playbook_versions', 'playbook_version_events', 'consent_events')) = 0
    THEN 'PASS' ELSE 'FAIL' END || ' no cascading or null-on-delete FK on a ledger table (ruling item 15)';

-- The release's migrations are all recorded.
SELECT CASE WHEN (SELECT count(*) FROM migrations WHERE migration IN (
    '2026_10_10_100000_add_cost_attribution_to_evaluation', '2026_10_10_100000_create_outcome_attribution_tables',
    '2026_10_10_300000_create_playbook_versions', '2026_10_10_700000_add_brand_to_consent_state',
    '2026_10_10_800000_add_identity_to_consent_scope', '2026_10_10_800000_create_follow_up_tables',
    '2026_10_11_100000_create_cross_business_quarantine_tables', '2026_10_15_100000_scope_customer_identities_to_business',
    '2026_10_15_400000_s4_outcome_followups', '2026_10_15_510000_add_business_id_to_decision_records')) = 10
    THEN 'PASS' ELSE 'FAIL' END || ' the ten wave 1-4 migrations are recorded in migrations';

ROLLBACK;
