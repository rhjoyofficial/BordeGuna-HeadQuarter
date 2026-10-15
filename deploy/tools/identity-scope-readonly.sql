-- identity-scope-readonly.sql (docs/121, ruling docs/120 A1)
--
-- READ ONLY. Quantifies the cross-business identity leak (docs/119 section 2) on the schema of the
-- production release b96a396 (customer_identities WITHOUT business_id; consent tables consent_events
-- and consent_state). Every statement is a SELECT; the whole file runs inside a READ ONLY transaction
-- that is rolled back, so PostgreSQL itself refuses any write.
--
-- Run (Joy, on the VPS; nothing here needs the app):
--   docker compose exec -T db psql -U <user> -d <db> -v ON_ERROR_STOP=1 -f - < identity-scope-readonly.sql
--
-- Each query returns counts first; the detail queries are LIMITed and carry no message text.
-- Phone numbers and e-mail addresses are never printed: values are shown as row ids only.

BEGIN READ ONLY;
SET LOCAL statement_timeout = '60s';

-- 0. Context ------------------------------------------------------------------------------------
SELECT now() AS run_at, current_database() AS db, version() AS pg_version;
SELECT max(migration) AS last_migration, count(*) AS migrations FROM migrations;
SELECT id, slug, business_id, is_active, deleted_at IS NOT NULL AS deleted FROM stores ORDER BY id;

-- 1. Liveness: are identity-writing paths still being fed? ------------------------------------
-- 1a. Domain events whose handlers resolve a customer (ShopOrderEventHandler for order.created and
--     order.updated, CustomerRegisteredHandler, MetaInboundHandler), by status, with the newest row.
SELECT type, status, count(*) AS n, max(created_at) AS newest, min(created_at) AS oldest
FROM events
WHERE type IN ('order.created', 'order.updated', 'customer.registered', 'conversation.inbound_received')
GROUP BY type, status
ORDER BY type, status;

-- 1b. The same, last 7 days and last 24 hours (a non-zero row means the path is live now).
SELECT type,
       count(*) FILTER (WHERE created_at >= now() - interval '7 days') AS last_7d,
       count(*) FILTER (WHERE created_at >= now() - interval '24 hours') AS last_24h,
       count(*) FILTER (WHERE status IN ('pending', 'failed', 'processing')) AS not_yet_processed
FROM events
WHERE type IN ('order.created', 'order.updated', 'customer.registered', 'conversation.inbound_received')
GROUP BY type
ORDER BY type;

-- 1c. Scheduled jobs that run an identity-writing tool (reconcile = FactsReconciler -> resolver).
SELECT id, name, kind, cron_expression, event_type, tool_key, state, approval_state, last_status
FROM scheduled_jobs
WHERE tool_key IN ('commerce.reconcile_facts', 'commerce.get_order_status', 'commerce.take_order', 'commerce.cancel_sales_order')
ORDER BY id;

-- 1d. Approvals still able to execute an identity-writing tool.
SELECT tool_key, status, count(*) AS n
FROM approvals
WHERE tool_key IN ('commerce.reconcile_facts', 'commerce.get_order_status', 'commerce.take_order', 'commerce.cancel_sales_order')
GROUP BY tool_key, status
ORDER BY tool_key, status;

-- 1e. Switches that gate the Meta inbound path and the AI runtime.
SELECT key, environment, enabled
FROM feature_flags
WHERE key IN ('channels.meta.inbound', 'channels.meta.forwarded', 'ai.runtime')
ORDER BY key, environment;

-- 2. Identities used across businesses -------------------------------------------------------
-- 2a. Store-scoped identities (shop_user, shop_order) held by a customer of ANOTHER business than
--     the store's: direct proof of a cross-business link.
SELECT i.channel, count(*) AS identities, count(DISTINCT i.customer_id) AS customers
FROM customer_identities i
JOIN customers c ON c.id = i.customer_id
JOIN stores s ON s.id = i.source_store_id
WHERE s.business_id <> c.business_id
GROUP BY i.channel
ORDER BY i.channel;

-- 2b. Phone identities (null store) whose number appears on an order of a business other than the
--     identity's customer's business: the resolver matched this phone across businesses.
SELECT count(DISTINCT i.id) AS phone_identities, count(DISTINCT i.customer_id) AS customers,
       count(DISTINCT f.id) AS foreign_orders
FROM customer_identities i
JOIN customers c ON c.id = i.customer_id
JOIN facts_orders f ON f.customer_phone_canonical = i.external_id AND f.business_id <> c.business_id
WHERE i.channel = 'phone' AND i.source_store_id IS NULL;

-- 2c. Identities linked to a conversation participant of another business than the identity's customer.
SELECT i.channel, count(DISTINCT i.id) AS identities, count(DISTINCT p.id) AS participants
FROM conversation_participants p
JOIN conversations v ON v.id = p.conversation_id
JOIN customer_identities i ON i.id = p.identity_id
JOIN customers c ON c.id = i.customer_id
WHERE c.business_id <> v.business_id
GROUP BY i.channel
ORDER BY i.channel;

-- 2d. Identities whose evidence names MORE THAN ONE business (owner, store, orders by phone, threads).
WITH evidence AS (
    SELECT i.id AS identity_id, c.business_id FROM customer_identities i JOIN customers c ON c.id = i.customer_id
    UNION
    SELECT i.id, s.business_id FROM customer_identities i JOIN stores s ON s.id = i.source_store_id
    UNION
    SELECT i.id, f.business_id FROM customer_identities i JOIN facts_orders f
        ON i.channel = 'phone' AND i.source_store_id IS NULL AND f.customer_phone_canonical = i.external_id
    UNION
    SELECT p.identity_id, v.business_id FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id
    WHERE p.identity_id IS NOT NULL
)
SELECT count(*) AS identities_with_more_than_one_business
FROM (SELECT identity_id FROM evidence GROUP BY identity_id HAVING count(DISTINCT business_id) > 1) x;

-- 3. Downstream rows in another business than their customer ---------------------------------
SELECT 'facts_orders' AS rows_of, count(*) AS n, count(DISTINCT f.customer_id) AS customers
FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id
UNION ALL
SELECT 'conversations', count(*), count(DISTINCT v.customer_id)
FROM conversations v JOIN customers c ON c.id = v.customer_id WHERE v.business_id <> c.business_id
UNION ALL
SELECT 'conversation_participants', count(*), count(DISTINCT p.customer_id)
FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id JOIN customers c ON c.id = p.customer_id
WHERE v.business_id <> c.business_id
UNION ALL
SELECT 'conversation_messages', count(*), count(DISTINCT m.customer_id)
FROM conversation_messages m JOIN conversations v ON v.id = m.conversation_id JOIN customers c ON c.id = m.customer_id
WHERE v.business_id <> c.business_id
UNION ALL
SELECT 'consent_events', count(*), count(DISTINCT e.customer_id)
FROM consent_events e JOIN customers c ON c.id = e.customer_id WHERE e.business_id <> c.business_id
UNION ALL
SELECT 'consent_state', count(*), count(DISTINCT s.customer_id)
FROM consent_state s JOIN customers c ON c.id = s.customer_id WHERE s.business_id <> c.business_id
UNION ALL
SELECT 'memories', count(*), count(DISTINCT mm.customer_id)
FROM memories mm JOIN customers c ON c.id = mm.customer_id WHERE mm.business_id IS NOT NULL AND mm.business_id <> c.business_id
UNION ALL
SELECT 'customer_merges (cross-business)', count(*), count(DISTINCT m.kept_id)
FROM customer_merges m JOIN customers k ON k.id = m.kept_id JOIN customers a ON a.id = m.absorbed_id
WHERE k.business_id <> a.business_id;

-- 3b. Distinct customers touched by any of the above.
SELECT count(*) AS customers_touched FROM (
    SELECT f.customer_id FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id
    UNION SELECT v.customer_id FROM conversations v JOIN customers c ON c.id = v.customer_id WHERE v.business_id <> c.business_id
    UNION SELECT p.customer_id FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id JOIN customers c ON c.id = p.customer_id WHERE v.business_id <> c.business_id
    UNION SELECT m.customer_id FROM conversation_messages m JOIN conversations v ON v.id = m.conversation_id JOIN customers c ON c.id = m.customer_id WHERE v.business_id <> c.business_id
    UNION SELECT e.customer_id FROM consent_events e JOIN customers c ON c.id = e.customer_id WHERE e.business_id <> c.business_id
) t;

-- 3c. Stored customer fields that only another business's orders explain: the phone on the customer
--     row matches no order of its own business but matches an order of another business (the
--     resolver filled an empty phone from a foreign order).
SELECT count(*) AS customers_with_phone_from_foreign_orders_only
FROM customers c
WHERE c.primary_phone_canonical IS NOT NULL
  AND EXISTS (SELECT 1 FROM facts_orders f WHERE f.customer_id = c.id AND f.business_id <> c.business_id
              AND f.customer_phone_canonical = c.primary_phone_canonical)
  AND NOT EXISTS (SELECT 1 FROM facts_orders f WHERE f.customer_id = c.id AND f.business_id = c.business_id
                  AND f.customer_phone_canonical = c.primary_phone_canonical);

-- 4. Duplicate null-store identities (the unique index does not hold them) -------------------
SELECT channel, count(*) AS duplicated_values, sum(n) AS rows_involved,
       count(*) FILTER (WHERE businesses > 1) AS values_spanning_businesses,
       count(*) FILTER (WHERE customers > 1 AND businesses = 1) AS values_with_two_customers_same_business
FROM (
    SELECT i.channel, i.external_id, count(*) AS n, count(DISTINCT i.customer_id) AS customers,
           count(DISTINCT c.business_id) AS businesses
    FROM customer_identities i JOIN customers c ON c.id = i.customer_id
    WHERE i.source_store_id IS NULL
    GROUP BY i.channel, i.external_id
    HAVING count(*) > 1
) d
GROUP BY channel
ORDER BY channel;

-- 5. Identities whose customer's business differs from the business of the rows that used them --
-- 5a. A shop_order identity (store-scoped) owned by a customer other than the one its order points
--     at: the identity was re-pointed after the order was written (the "steal", docs/119 2.3).
SELECT count(*) AS order_identity_owner_differs_from_order_customer,
       count(*) FILTER (WHERE ci.business_id <> f.business_id) AS of_which_owner_in_other_business
FROM facts_orders f
JOIN customer_identities i ON i.channel = 'shop_order' AND i.external_id = f.order_ref AND i.source_store_id = f.store_id
JOIN customers ci ON ci.id = i.customer_id
WHERE i.customer_id <> f.customer_id;

-- 5b. Orders whose customer is not the owner of the order's phone identity (phone moved since).
SELECT count(*) AS orders_whose_phone_identity_now_belongs_elsewhere,
       count(*) FILTER (WHERE ci.business_id <> f.business_id) AS of_which_in_other_business
FROM facts_orders f
JOIN customer_identities i ON i.channel = 'phone' AND i.source_store_id IS NULL AND i.external_id = f.customer_phone_canonical
JOIN customers ci ON ci.id = i.customer_id
WHERE i.customer_id <> f.customer_id;

-- 5c. Per business pair (row business -> customer business), the rows involved.
SELECT 'facts_orders' AS rows_of, f.business_id AS row_business, c.business_id AS customer_business, count(*) AS n
FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id
GROUP BY f.business_id, c.business_id
UNION ALL
SELECT 'conversations', v.business_id, c.business_id, count(*)
FROM conversations v JOIN customers c ON c.id = v.customer_id WHERE v.business_id <> c.business_id
GROUP BY v.business_id, c.business_id
UNION ALL
SELECT 'consent_events', e.business_id, c.business_id, count(*)
FROM consent_events e JOIN customers c ON c.id = e.customer_id WHERE e.business_id <> c.business_id
GROUP BY e.business_id, c.business_id
ORDER BY 1, 2, 3;

-- 6. Detail (ids only, first 50) for a person to review ----------------------------------------
SELECT f.id AS facts_order_id, f.business_id AS order_business, f.store_id, f.customer_id, c.business_id AS customer_business, f.placed_at
FROM facts_orders f JOIN customers c ON c.id = f.customer_id
WHERE f.business_id <> c.business_id
ORDER BY f.id
LIMIT 50;

SELECT v.id AS conversation_id, v.business_id AS conversation_business, v.channel, v.customer_id, c.business_id AS customer_business, v.created_at
FROM conversations v JOIN customers c ON c.id = v.customer_id
WHERE v.business_id <> c.business_id
ORDER BY v.id
LIMIT 50;

ROLLBACK;
