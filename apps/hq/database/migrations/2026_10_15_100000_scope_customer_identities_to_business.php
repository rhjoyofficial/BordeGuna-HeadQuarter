<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/122 (ruling docs/120 A2, Option A): every customer identity belongs to exactly one business.
 *
 * Schema:
 *  - `customer_identities.business_id` NOT NULL, held to its customer's business by the composite foreign key
 *    (customer_id, business_id) -> customers (id, business_id) (customers gains the unique key it needs).
 *  - A non-null source store belongs to the same business: (live_store_id, business_id) -> stores (id, business_id),
 *    where `live_store_id` is a stored generated column equal to `source_store_id` on a live row.
 *  - The old unique key (channel, external_id, source_store_id), which never constrained a null store because
 *    PostgreSQL treats NULLs as distinct, is replaced by UNIQUE (business_id, channel, external_id, source_store_id)
 *    NULLS NOT DISTINCT over live rows.
 *
 * Backfill, from reliable evidence only (A2 item 6). A row first takes its owner's business (the only value the
 * composite key allows). It is then QUARANTINED, never guessed, when the evidence contradicts that owner:
 *  - store_business_mismatch: a store-scoped id (shop_user, shop_order) hanging under another business's customer;
 *  - contradicted_by_evidence: a store-less id that orders, thread participants or consent rows used only in
 *    businesses other than its owner's;
 *  - duplicate_same_customer: a second copy of the same key for the same customer (the lowest id stays live);
 *  - duplicate_ambiguous_owner: one key held by two customers of one business (every copy is held).
 * A quarantined row keeps its customer, its references and its lineage; no lookup sees it, and each one gets an
 * open `customer_identity_conflicts` row so a person (or the docs/120 A3 repair) can resolve it with evidence.
 *
 * `customer_identity_conflicts` is also where the resolver writes a refused re-point: an identity already held by
 * another customer is never moved by a lookup (A2 "no silent re-point").
 *
 * Down: real while it loses nothing. It refuses (and says why) when a conflict has been recorded, or when the old
 * unique key could no longer hold; then the way back is the pre-migration backup (docs/122 §Down).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('customer identity business scope needs PostgreSQL 15+ (NULLS NOT DISTINCT).');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customers ADD CONSTRAINT customers_id_business_unique UNIQUE (id, business_id);
            ALTER TABLE stores ADD CONSTRAINT stores_id_business_unique UNIQUE (id, business_id);

            ALTER TABLE customer_identities
                ADD COLUMN business_id bigint NULL,
                ADD COLUMN quarantined_at timestamptz(0) NULL,
                ADD COLUMN quarantine_reason varchar(48) NULL;

            -- 1. The owner's business: the only value the composite key will accept.
            UPDATE customer_identities i SET business_id = c.business_id FROM customers c WHERE c.id = i.customer_id;

            -- 2. A store-scoped id belongs to its store's business; under another business's customer it is held.
            UPDATE customer_identities i
               SET quarantined_at = now(), quarantine_reason = 'store_business_mismatch'
              FROM stores s
             WHERE s.id = i.source_store_id AND s.business_id <> i.business_id;

            -- 3. A store-less id whose every use is in another business than its owner's is held.
            WITH evidence AS (
                SELECT i.id AS identity_id, f.business_id
                  FROM customer_identities i
                  JOIN facts_orders f ON f.customer_phone_canonical = i.external_id
                 WHERE i.channel = 'phone' AND i.source_store_id IS NULL
                UNION
                SELECT p.identity_id, v.business_id
                  FROM conversation_participants p JOIN conversations v ON v.id = p.conversation_id
                 WHERE p.identity_id IS NOT NULL
                UNION
                SELECT e.identity_id, e.business_id FROM consent_events e WHERE e.identity_id IS NOT NULL
            )
            UPDATE customer_identities i
               SET quarantined_at = now(), quarantine_reason = 'contradicted_by_evidence'
             WHERE i.quarantined_at IS NULL
               AND i.source_store_id IS NULL
               AND EXISTS (SELECT 1 FROM evidence e WHERE e.identity_id = i.id)
               AND NOT EXISTS (SELECT 1 FROM evidence e WHERE e.identity_id = i.id AND e.business_id = i.business_id);

            -- 4. Two live copies of one key in one business: a duplicate of one customer keeps its lowest id live,
            --    two customers on one key are both held (which one owns it is not knowable from the rows).
            WITH ranked AS (
                SELECT id,
                       row_number() OVER w AS rn,
                       count(*) OVER w AS copies,
                       min(customer_id) OVER w AS low_customer,
                       max(customer_id) OVER w AS high_customer
                  FROM customer_identities
                 WHERE quarantined_at IS NULL
                WINDOW w AS (PARTITION BY business_id, channel, external_id, source_store_id ORDER BY id
                             ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
            )
            UPDATE customer_identities i
               SET quarantined_at = now(),
                   quarantine_reason = CASE WHEN r.low_customer = r.high_customer THEN 'duplicate_same_customer' ELSE 'duplicate_ambiguous_owner' END
              FROM ranked r
             WHERE r.id = i.id AND r.copies > 1 AND (r.low_customer <> r.high_customer OR r.rn > 1);

            ALTER TABLE customer_identities ALTER COLUMN business_id SET NOT NULL;
            ALTER TABLE customer_identities
                ADD CONSTRAINT customer_identities_business_id_foreign FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE RESTRICT,
                ADD CONSTRAINT customer_identities_customer_business_foreign FOREIGN KEY (customer_id, business_id) REFERENCES customers (id, business_id) ON DELETE CASCADE,
                ADD CONSTRAINT customer_identities_quarantine_check CHECK ((quarantined_at IS NULL) = (quarantine_reason IS NULL)),
                ADD CONSTRAINT customer_identities_quarantine_reason_check CHECK (quarantine_reason IS NULL OR quarantine_reason IN ('store_business_mismatch', 'contradicted_by_evidence', 'duplicate_same_customer', 'duplicate_ambiguous_owner'));

            ALTER TABLE customer_identities
                ADD COLUMN live_store_id bigint GENERATED ALWAYS AS (CASE WHEN quarantined_at IS NULL THEN source_store_id END) STORED;
            ALTER TABLE customer_identities
                ADD CONSTRAINT customer_identities_store_business_foreign FOREIGN KEY (live_store_id, business_id) REFERENCES stores (id, business_id) ON DELETE RESTRICT;

            ALTER TABLE customer_identities DROP CONSTRAINT customer_identities_channel_unique;
            CREATE UNIQUE INDEX customer_identities_business_key
                ON customer_identities (business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT
                WHERE quarantined_at IS NULL;
            CREATE INDEX customer_identities_quarantined_index ON customer_identities (business_id, channel, external_id) WHERE quarantined_at IS NOT NULL;

            CREATE TABLE customer_identity_conflicts (
                id bigserial PRIMARY KEY,
                business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                identity_id bigint NOT NULL REFERENCES customer_identities (id) ON DELETE RESTRICT,
                kind varchar(24) NOT NULL CHECK (kind IN ('link_refused', 'quarantined')),
                reason varchar(48) NOT NULL,
                holder_customer_id bigint NULL REFERENCES customers (id) ON DELETE RESTRICT,
                claimant_customer_id bigint NULL REFERENCES customers (id) ON DELETE RESTRICT,
                source varchar(48) NOT NULL,
                context jsonb NULL,
                occurrences integer NOT NULL DEFAULT 1,
                first_seen_at timestamptz(0) NOT NULL,
                last_seen_at timestamptz(0) NOT NULL,
                resolved_at timestamptz(0) NULL,
                resolved_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                resolution varchar(48) NULL,
                created_at timestamptz(0) NULL,
                updated_at timestamptz(0) NULL,
                CONSTRAINT customer_identity_conflicts_resolution_check CHECK ((resolved_at IS NULL) = (resolution IS NULL))
            );
            CREATE UNIQUE INDEX customer_identity_conflicts_open_key
                ON customer_identity_conflicts (identity_id, kind, claimant_customer_id) NULLS NOT DISTINCT
                WHERE resolved_at IS NULL;
            CREATE INDEX customer_identity_conflicts_business_index ON customer_identity_conflicts (business_id, resolved_at);

            -- 5. Every held row is an open resolution requirement.
            INSERT INTO customer_identity_conflicts
                (business_id, identity_id, kind, reason, holder_customer_id, source, context, first_seen_at, last_seen_at, created_at, updated_at)
            SELECT i.business_id, i.id, 'quarantined', i.quarantine_reason, i.customer_id, 'migration.2026_10_15_100000',
                   jsonb_build_object('channel', i.channel, 'source_store_id', i.source_store_id), now(), now(), now(), now()
              FROM customer_identities i
             WHERE i.quarantined_at IS NOT NULL
             ORDER BY i.id;
        SQL);
    }

    public function down(): void
    {
        $recorded = DB::table('customer_identity_conflicts')->count();
        if ($recorded > 0) {
            throw new RuntimeException("Refusing to roll back: {$recorded} identity conflict(s) are recorded, and dropping them loses audit evidence. Restore the pre-migration backup instead (docs/122 §Down).");
        }
        $clash = DB::selectOne('SELECT count(*) AS n FROM (SELECT 1 FROM customer_identities WHERE source_store_id IS NOT NULL GROUP BY channel, external_id, source_store_id HAVING count(*) > 1) d')->n;
        if ((int) $clash > 0) {
            throw new RuntimeException("Refusing to roll back: {$clash} store-scoped key(s) now exist in more than one row, which the old unique key cannot hold. Restore the pre-migration backup instead (docs/122 §Down).");
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE customer_identity_conflicts;
            DROP INDEX customer_identities_quarantined_index;
            DROP INDEX customer_identities_business_key;
            ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_channel_unique UNIQUE (channel, external_id, source_store_id);
            ALTER TABLE customer_identities
                DROP CONSTRAINT customer_identities_store_business_foreign,
                DROP COLUMN live_store_id,
                DROP CONSTRAINT customer_identities_customer_business_foreign,
                DROP CONSTRAINT customer_identities_business_id_foreign,
                DROP CONSTRAINT customer_identities_quarantine_check,
                DROP CONSTRAINT customer_identities_quarantine_reason_check,
                DROP COLUMN quarantine_reason,
                DROP COLUMN quarantined_at,
                DROP COLUMN business_id;
            ALTER TABLE stores DROP CONSTRAINT stores_id_business_unique;
            ALTER TABLE customers DROP CONSTRAINT customers_id_business_unique;
        SQL);
    }
};
