-- show-quarantine.sql (deploy/rehearsal, docs/128 D.1): what the W1 migration did with the rows, seeded or real.
--
-- READ ONLY (SELECTs in a READ ONLY transaction, rolled back). Written for the schema AFTER
-- 2026_10_15_100000_scope_customer_identities_to_business. Ids and reasons only: no phone, e-mail or message text.
BEGIN READ ONLY;
SET LOCAL statement_timeout = '60s';

SELECT 'quarantined_by_reason ' || quarantine_reason || ' ' || count(*)
FROM customer_identities WHERE quarantined_at IS NOT NULL GROUP BY quarantine_reason ORDER BY quarantine_reason;

SELECT 'live_identities ' || count(*) FROM customer_identities WHERE quarantined_at IS NULL;

SELECT 'open_conflicts ' || count(*) || ' sources=' || string_agg(DISTINCT source, ',')
FROM customer_identity_conflicts WHERE resolved_at IS NULL;

-- The seeded rows, when the seed ran (empty otherwise).
SELECT 'seeded identity ' || i.id || ' ' || i.channel || ' business=' || i.business_id || ' store=' || coalesce(i.source_store_id::text, 'null')
       || ' customer=' || i.customer_id || ' quarantined=' || coalesce(i.quarantine_reason, 'no')
       || ' conflict=' || coalesce((SELECT string_agg(k.kind || '/' || k.reason, ',') FROM customer_identity_conflicts k WHERE k.identity_id = i.id AND k.resolved_at IS NULL), 'none')
FROM customer_identities i
WHERE i.external_id LIKE 'REHEARSAL%' OR i.external_id IN ('01799000001', 'rehearsal-shared@example.invalid')
ORDER BY i.id;

SELECT 'seeded order ' || f.id || ' business=' || f.business_id || ' still_on_customer=' || f.customer_id
       || ' customer_business=' || c.business_id || ' cross_business=' || (f.business_id <> c.business_id)
FROM facts_orders f JOIN customers c ON c.id = f.customer_id
WHERE f.order_ref LIKE 'REHEARSAL%' ORDER BY f.id;

-- Every store-scoped identity under a customer of another business, real or seeded: all must be quarantined.
SELECT 'store_mismatch_total ' || count(*) || ' quarantined=' || count(*) FILTER (WHERE i.quarantined_at IS NOT NULL)
FROM customer_identities i JOIN stores s ON s.id = i.source_store_id WHERE s.business_id <> i.business_id;

-- Orders still hanging under a customer of another business (the W2 repair's work, untouched by W1).
SELECT 'cross_business_orders_remaining ' || count(*)
FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id;

ROLLBACK;
