-- seed-corruption.sql (deploy/rehearsal --seed-corruption, docs/128 D.1): plant the docs/121 section 5 shape, and the
-- other corruptions the ruling names (null-store duplicates, ambiguous ownership, evidence in another business),
-- into the REHEARSAL copy BEFORE the release migrates it, so the W1 migration's quarantine can be watched.
--
-- ⛔ WRITES. Only ever run against the rehearsal database (rehearse.sh refuses a production database name before
-- it gets here). Written for the b96a396 schema: customer_identities WITHOUT business_id.
--
-- Variables (psql -v): laladia_business_id, bionic_business_id, bionic_store_id, laladia_store_id. The rows are
-- marked REHEARSAL in every name and reference so they can be told apart from real rows in every listing.
--
-- Shape 1 (docs/121 section 5, the production leak): a Bionic order fact on a LaLaDia customer, with the Bionic
--   store's shop_user and shop_order identities hanging under that LaLaDia customer, and a phone identity the
--   LaLaDia customer's own order also uses (so the phone stays live, as on production).
-- Shape 2: a second copy of the same phone under the same customer (duplicate_same_customer).
-- Shape 3: one e-mail held by two LaLaDia customers (duplicate_ambiguous_owner, both held).
-- Shape 4: a Messenger PSID under the LaLaDia customer whose only use is a Bionic thread's participant
--   (contradicted_by_evidence).
BEGIN;

INSERT INTO customers (business_id, name, primary_phone_canonical, first_seen_at, last_seen_at, created_at, updated_at)
VALUES (:laladia_business_id, 'REHEARSAL seeded leak customer', '01799000001', now(), now(), now(), now())
RETURNING id AS rehearsal_customer_id \gset

INSERT INTO customers (business_id, name, first_seen_at, last_seen_at, created_at, updated_at)
VALUES (:laladia_business_id, 'REHEARSAL seeded second owner', now(), now(), now(), now())
RETURNING id AS rehearsal_customer2_id \gset

-- Shape 1
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at) VALUES
    (:rehearsal_customer_id, 'phone', '01799000001', NULL, 1, now(), now()),
    (:rehearsal_customer_id, 'shop_user', 'REHEARSAL-BNC-USER-1', :bionic_store_id, 1, now(), now()),
    (:rehearsal_customer_id, 'shop_order', 'REHEARSAL-BNC-1', :bionic_store_id, 1, now(), now()),
    (:rehearsal_customer_id, 'shop_order', 'REHEARSAL-LLD-1', :laladia_store_id, 1, now(), now());

INSERT INTO facts_orders (business_id, store_id, order_ref, status, gross, customer_id, customer_phone_canonical, placed_at, created_at, updated_at) VALUES
    (:bionic_business_id, :bionic_store_id, 'REHEARSAL-BNC-1', 'pending', 1, :rehearsal_customer_id, '01799000001', now(), now(), now()),
    (:laladia_business_id, :laladia_store_id, 'REHEARSAL-LLD-1', 'pending', 1, :rehearsal_customer_id, '01799000001', now(), now(), now());

-- Shape 2: a duplicate copy of the phone (possible on b96a396 because NULL stores were never unique)
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
VALUES (:rehearsal_customer_id, 'phone', '01799000001', NULL, 1, now(), now());

-- Shape 3: one e-mail, two customers of one business
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at) VALUES
    (:rehearsal_customer_id, 'email', 'rehearsal-shared@example.invalid', NULL, 1, now(), now()),
    (:rehearsal_customer2_id, 'email', 'rehearsal-shared@example.invalid', NULL, 1, now(), now());

-- Shape 4: a PSID used only by a Bionic thread
INSERT INTO customer_identities (customer_id, channel, external_id, source_store_id, confidence, created_at, updated_at)
VALUES (:rehearsal_customer_id, 'messenger_psid', 'REHEARSAL-PSID-BNC', NULL, 1, now(), now())
RETURNING id AS rehearsal_psid_identity_id \gset

INSERT INTO conversations (uuid, business_id, customer_id, channel, external_thread_ref, status, opened_at, created_at, updated_at)
VALUES (gen_random_uuid(), :bionic_business_id, :rehearsal_customer_id, 'messenger', 'REHEARSAL-THREAD-BNC', 'open', now(), now(), now())
RETURNING id AS rehearsal_conversation_id \gset

INSERT INTO conversation_participants (conversation_id, participant_type, customer_id, identity_id, role, joined_at, created_at, updated_at)
VALUES (:rehearsal_conversation_id, 'customer', :rehearsal_customer_id, :rehearsal_psid_identity_id, 'participant', now(), now(), now());

COMMIT;

-- What was planted (ids only, no values that could be mistaken for a person's).
SELECT 'seeded customer ' || :rehearsal_customer_id || ' (LaLaDia) and customer ' || :rehearsal_customer2_id;
SELECT 'seeded identity ' || id || ' ' || channel || ' store=' || coalesce(source_store_id::text, 'null') || ' customer=' || customer_id
FROM customer_identities WHERE external_id LIKE 'REHEARSAL%' OR external_id IN ('01799000001', 'rehearsal-shared@example.invalid') ORDER BY id;
SELECT 'seeded order ' || id || ' business=' || business_id || ' store=' || store_id || ' customer=' || customer_id FROM facts_orders WHERE order_ref LIKE 'REHEARSAL%' ORDER BY id;
SELECT 'seeded conversation ' || :rehearsal_conversation_id || ' (Bionic) participant identity ' || :rehearsal_psid_identity_id;
