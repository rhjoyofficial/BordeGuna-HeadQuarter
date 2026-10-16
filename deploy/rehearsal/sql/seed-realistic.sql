-- seed-realistic.sql (deploy/rehearsal/local-sql-rehearsal.sh, docs/128 D.1): realistic CLEAN rows for a database that
-- has the production schema of b96a396 but no production data (the local SQL-level rehearsal has no dump).
--
-- ⛔ WRITES. Only ever run against a rehearsal database (local-sql-rehearsal.sh refuses any name not prefixed
-- hq_rehearsal_local). Written for the b96a396 schema: customer_identities WITHOUT business_id.
--
-- Shape, modelled on production's real volume (docs/121 section 5: tens of orders, two live shops):
--   LaLaDia: 40 customers, 60 orders on store laladia-com; Bionic: 25 customers, 30 orders on store bionic-garden.
--   Every customer: one phone identity (no store). Every order: one shop_order identity on its own store, and
--   every second customer one shop_user identity on its own store. Six Messenger conversations (four LaLaDia,
--   two Bionic), each with a PSID identity, a participant row naming it, and three messages. Two consent events
--   and their projection rows. Twelve decision records whose context names a conversation, an agent run or an
--   approval (so the decision_records backfill has reliable and unreliable rows), four agent runs, one
--   evaluation suite with two cases and one run with two results (so the cost columns have rows to sit beside).
-- Every name, reference and body says RLOCAL (rehearsal, local), so it never collides with seed-corruption.sql's REHEARSAL rows
-- and stays out of show-quarantine.sql's 'seeded' listing. No real phone, e-mail or message text.
BEGIN;

SELECT id AS lld_b FROM businesses WHERE slug = 'laladia' \gset
SELECT id AS bnc_b FROM businesses WHERE slug = 'bionic' \gset
SELECT id AS lld_s FROM stores WHERE slug = 'laladia-com' \gset
SELECT id AS bnc_s FROM stores WHERE slug = 'bionic-garden' \gset

-- Customers
INSERT INTO customers (business_id, name, primary_phone_canonical, first_seen_at, last_seen_at, orders_count, created_at, updated_at)
SELECT :lld_b, 'RLOCAL LaLaDia customer ' || g, '0171100' || lpad(g::text, 4, '0'), now() - (g || ' days')::interval, now(), 0, now(), now()
FROM generate_series(1, 40) g;
INSERT INTO customers (business_id, name, primary_phone_canonical, first_seen_at, last_seen_at, orders_count, created_at, updated_at)
SELECT :bnc_b, 'RLOCAL Bionic customer ' || g, '0181100' || lpad(g::text, 4, '0'), now() - (g || ' days')::interval, now(), 0, now(), now()
FROM generate_series(1, 25) g;

-- Phone identities (no store), one per customer
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
SELECT id, 'phone', primary_phone_canonical, NULL, 1, now(), now() FROM customers WHERE name LIKE 'RLOCAL % customer %';

-- Shop users, every second customer, on the customer's own business's store
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
SELECT c.id, 'shop_user', 'RLOCAL-USER-' || c.id, CASE WHEN c.business_id = :lld_b THEN :lld_s ELSE :bnc_s END, 1, now(), now()
FROM customers c WHERE c.name LIKE 'RLOCAL % customer %' AND c.id % 2 = 0;

-- Orders: LaLaDia 60 over its 40 customers, Bionic 30 over its 25
INSERT INTO facts_orders (business_id, store_id, order_ref, source, status, gross, customer_id, customer_phone_canonical, placed_at, created_at, updated_at)
SELECT :lld_b, :lld_s, 'RLOCAL-LLD-' || g, 'webhook', (ARRAY['pending','confirmed','delivered','cancelled'])[1 + g % 4], 500 + g * 10,
       c.id, c.primary_phone_canonical, now() - (g || ' hours')::interval, now(), now()
FROM generate_series(1, 60) g
JOIN LATERAL (SELECT id, primary_phone_canonical FROM customers WHERE business_id = :lld_b AND name LIKE 'RLOCAL%' ORDER BY id OFFSET ((g - 1) % 40) LIMIT 1) c ON true;
INSERT INTO facts_orders (business_id, store_id, order_ref, source, status, gross, customer_id, customer_phone_canonical, placed_at, created_at, updated_at)
SELECT :bnc_b, :bnc_s, 'RLOCAL-BNC-' || g, 'webhook', (ARRAY['pending','confirmed','delivered','cancelled'])[1 + g % 4], 300 + g * 10,
       c.id, c.primary_phone_canonical, now() - (g || ' hours')::interval, now(), now()
FROM generate_series(1, 30) g
JOIN LATERAL (SELECT id, primary_phone_canonical FROM customers WHERE business_id = :bnc_b AND name LIKE 'RLOCAL%' ORDER BY id OFFSET ((g - 1) % 25) LIMIT 1) c ON true;

-- One shop_order identity per order, on the order's own store, under the order's own customer
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
SELECT customer_id, 'shop_order', order_ref, store_id, 1, now(), now() FROM facts_orders WHERE order_ref LIKE 'RLOCAL-%';

UPDATE customers c SET orders_count = o.n FROM (SELECT customer_id, count(*) n FROM facts_orders WHERE order_ref LIKE 'RLOCAL-%' GROUP BY customer_id) o
WHERE o.customer_id = c.id;

-- Conversations: four LaLaDia, two Bionic, each with a PSID identity, a participant and three messages
CREATE TEMP TABLE rehearsal_conv ON COMMIT DROP AS
SELECT g, CASE WHEN g <= 4 THEN :lld_b ELSE :bnc_b END AS business_id,
       (SELECT id FROM customers WHERE business_id = CASE WHEN g <= 4 THEN :lld_b ELSE :bnc_b END AND name LIKE 'RLOCAL%' ORDER BY id OFFSET g LIMIT 1) AS customer_id
FROM generate_series(1, 6) g;

INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
SELECT customer_id, 'messenger_psid', 'RLOCAL-PSID-' || g, NULL, 1, now(), now() FROM rehearsal_conv;

INSERT INTO conversations (uuid, business_id, customer_id, channel, external_thread_ref, status, opened_at, last_message_at, created_at, updated_at)
SELECT gen_random_uuid(), business_id, customer_id, 'messenger', 'RLOCAL-THREAD-' || g, 'open', now() - interval '2 days', now(), now(), now() FROM rehearsal_conv;

INSERT INTO conversation_participants (conversation_id, participant_type, customer_id, identity_id, role, joined_at, created_at, updated_at)
SELECT v.id, 'customer', v.customer_id, i.id, 'participant', now(), now(), now()
FROM conversations v JOIN customer_identities i ON i.external_id = 'RLOCAL-PSID-' || substring(v.external_thread_ref FROM 'RLOCAL-THREAD-(\d+)')
WHERE v.external_thread_ref LIKE 'RLOCAL-THREAD-%';

INSERT INTO conversation_messages (uuid, conversation_id, direction, author_type, customer_id, channel, external_message_ref, body, status, received_at, created_at, updated_at)
SELECT gen_random_uuid(), v.id, 'inbound', 'customer', v.customer_id, 'messenger', 'RLOCAL-MSG-' || v.id || '-' || m, 'RLOCAL message ' || m, 'received', now(), now(), now()
FROM conversations v CROSS JOIN generate_series(1, 3) m WHERE v.external_thread_ref LIKE 'RLOCAL-THREAD-%';

-- Consent: two events and their projection rows (customer-wide, no brand, no identity: the b96a396 shape)
INSERT INTO consent_events (customer_id, business_id, channel, purpose, state, basis, occurred_at, recorded_at, actor_type, actor_label, reason)
SELECT customer_id, business_id, 'messenger', 'marketing', 'denied', 'customer_request', now(), now(), 'user', 'RLOCAL', 'RLOCAL seeded'
FROM rehearsal_conv WHERE g IN (1, 5);
INSERT INTO consent_state (customer_id, business_id, channel, purpose, state, effective_from, last_event_id, created_at, updated_at)
SELECT customer_id, business_id, channel, purpose, state, occurred_at, id, now(), now() FROM consent_events WHERE actor_label = 'RLOCAL';

-- Agent runs (two with a business, two without) and decision records naming conversations, runs and nothing reliable
INSERT INTO agent_runs (uuid, agent_key, correlation_id, trigger, business_id, question, autonomy_level, status, started_at, finished_at, created_at, updated_at)
SELECT gen_random_uuid(), 'rlocal-agent', 'RLOCAL-CORR-' || g, 'manual', CASE WHEN g <= 2 THEN :lld_b ELSE NULL END, 'RLOCAL question ' || g, 'A0', 'completed', now(), now(), now(), now()
FROM generate_series(1, 4) g;

INSERT INTO decision_records (agent_key, context_type, context_id, decision, reason, created_at)
SELECT 'rlocal-agent', 'conversation', uuid::text, 'accepted', 'RLOCAL', now() FROM conversations WHERE external_thread_ref LIKE 'RLOCAL-THREAD-%';
INSERT INTO decision_records (agent_key, context_type, context_id, decision, reason, created_at)
SELECT 'rlocal-agent', 'agent_run', uuid::text, 'rejected', 'RLOCAL', now() FROM agent_runs WHERE agent_key = 'rlocal-agent';
INSERT INTO decision_records (agent_key, context_type, context_id, decision, reason, created_at)
VALUES ('rlocal-agent', 'approval', '999999999', 'accepted', 'RLOCAL no such approval', now()),
       ('rlocal-agent', 'free_text', 'RLOCAL-none', 'accepted', 'RLOCAL no reliable context', now());

-- Evaluation: one suite, two cases, one run, two results (cost columns arrive with migration 1)
INSERT INTO evaluation_suites (key, name, purpose, kind, is_permanent, is_active, created_at, updated_at)
VALUES ('rlocal-suite', 'RLOCAL suite', 'RLOCAL', 'regression', false, true, now(), now())
RETURNING id AS suite_id \gset
INSERT INTO evaluation_cases (suite_id, key, purpose, input, expectation, is_active, created_at, updated_at)
SELECT :suite_id, 'rlocal-case-' || g, 'RLOCAL', '{}'::jsonb, '{}'::jsonb, true, now(), now() FROM generate_series(1, 2) g;
INSERT INTO evaluation_runs (uuid, suite_id, model, status, cases_total, cases_passed, cases_failed, cost_usd, started_at, finished_at, created_at, updated_at)
VALUES (gen_random_uuid(), :suite_id, 'rlocal-model', 'completed', 2, 1, 1, 0, now(), now(), now(), now())
RETURNING id AS run_id \gset
INSERT INTO evaluation_results (run_id, case_id, passed, grader, created_at, updated_at)
SELECT :run_id, id, key = 'rlocal-case-1', 'exact', now(), now() FROM evaluation_cases WHERE suite_id = :suite_id;

COMMIT;

SELECT 'seeded_realistic customers ' || count(*) FROM customers WHERE name LIKE 'RLOCAL%';
SELECT 'seeded_realistic identities ' || count(*) FROM customer_identities;
SELECT 'seeded_realistic orders ' || count(*) FROM facts_orders WHERE order_ref LIKE 'RLOCAL-%';
SELECT 'seeded_realistic conversations ' || count(*) FROM conversations WHERE external_thread_ref LIKE 'RLOCAL-THREAD-%';
SELECT 'seeded_realistic decision_records ' || count(*) FROM decision_records WHERE agent_key = 'rlocal-agent';
