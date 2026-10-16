-- post-release-scope-readonly.sql (docs/128; ruling docs/120 D.4): business scope AFTER the release.
--
-- READ ONLY (SELECTs in BEGIN READ ONLY ... ROLLBACK; the checklist adds PGOPTIONS default_transaction_read_only).
-- Needs the candidate schema (customer_identities.business_id, customer_identity_conflicts): it is the D.4 twin of
-- deploy/tools/identity-scope-readonly.sql, which reads the b96a396 schema. Counts and ids only.
--
--   docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T -e PGOPTIONS='-c default_transaction_read_only=on' \
--     db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/tools/post-release-scope-readonly.sql
--
-- Expected on production after the release and BEFORE the W2 repair (docs/121 section 5): every `*_mismatch`
-- and `*_live_*` line 0; quarantined store_business_mismatch 2; open conflicts 2; cross_business_orders 1 (order
-- #16, the repair's work); cross_business_conversations/participants/messages/consent 0.
BEGIN READ ONLY;
SET LOCAL statement_timeout = '60s';

-- Identity lookups are business-scoped: every identity carries its owner's business, and no LIVE identity names a
-- store of another business (the composite FKs enforce both; these prove no row escaped).
SELECT 'identity_owner_business_mismatch ' || count(*) FROM customer_identities i JOIN customers c ON c.id = i.customer_id WHERE c.business_id <> i.business_id;
SELECT 'identity_live_store_other_business ' || count(*) FROM customer_identities i JOIN stores s ON s.id = i.source_store_id WHERE i.quarantined_at IS NULL AND s.business_id <> i.business_id;
SELECT 'identity_live_duplicate_keys ' || count(*) FROM (SELECT 1 FROM customer_identities WHERE quarantined_at IS NULL GROUP BY business_id, channel, external_id, source_store_id HAVING count(*) > 1) d;

-- Unresolved and withheld identities: what W1 quarantined, and the open conflict rows that hold them.
SELECT 'identities_quarantined reason=' || quarantine_reason || ' ' || count(*) FROM customer_identities WHERE quarantined_at IS NOT NULL GROUP BY quarantine_reason ORDER BY quarantine_reason;
SELECT 'identity_conflicts_open kind=' || kind || ' reason=' || reason || ' ' || count(*) FROM customer_identity_conflicts WHERE resolved_at IS NULL GROUP BY kind, reason ORDER BY kind, reason;
SELECT 'quarantined_without_open_conflict ' || count(*) FROM customer_identities i
WHERE i.quarantined_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM customer_identity_conflicts k WHERE k.identity_id = i.id AND k.resolved_at IS NULL);
-- A thread or a consent row that still points at a quarantined identity (fail-closed means none is used to send).
SELECT 'participants_on_quarantined_identity ' || count(*) FROM conversation_participants p JOIN customer_identities i ON i.id = p.identity_id WHERE i.quarantined_at IS NOT NULL;
SELECT 'consent_events_on_quarantined_identity ' || count(*) FROM consent_events e JOIN customer_identities i ON i.id = e.identity_id WHERE i.quarantined_at IS NOT NULL;

-- Cross-business rows the release does NOT repair (W2's work): Customer 360, inbox and support read through these.
SELECT 'cross_business_orders ' || count(*) FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id;
SELECT 'cross_business_conversations ' || count(*) FROM conversations v JOIN customers c ON c.id = v.customer_id WHERE v.business_id <> c.business_id;
SELECT 'cross_business_participants ' || count(*) FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id JOIN customers c ON c.id = p.customer_id WHERE v.business_id <> c.business_id;
SELECT 'cross_business_participant_identities ' || count(*) FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id JOIN customer_identities i ON i.id = p.identity_id WHERE v.business_id <> i.business_id;
SELECT 'cross_business_messages ' || count(*) FROM conversation_messages m JOIN conversations v ON v.id = m.conversation_id JOIN customers c ON c.id = m.customer_id WHERE v.business_id <> c.business_id;
SELECT 'cross_business_consent_events ' || count(*) FROM consent_events e JOIN customers c ON c.id = e.customer_id WHERE e.business_id <> c.business_id;
SELECT 'cross_business_consent_state ' || count(*) FROM consent_state s JOIN customers c ON c.id = s.customer_id WHERE s.business_id <> c.business_id;
SELECT 'cross_business_merges ' || count(*) FROM customer_merges m JOIN customers a ON a.id = m.kept_id JOIN customers b ON b.id = m.absorbed_id WHERE a.business_id <> b.business_id;

-- The quarantine ledger (docs/117): flags written by hq:identity:cross-business --flag only. A release writes none.
SELECT 'cross_business_quarantine_flags ' || count(*) FROM cross_business_quarantines;

-- decision_records.business_id (docs/125): assigned from reliable context only; the rest listed, never guessed.
SELECT 'decision_records assigned=' || count(*) FILTER (WHERE business_id IS NOT NULL) || ' unassigned=' || count(*) FILTER (WHERE business_id IS NULL) FROM decision_records;

ROLLBACK;
