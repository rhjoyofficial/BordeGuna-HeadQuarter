<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/126 (ruling docs/120 A3, items 36, 37, 39): the evidence-backed repair of rows that hang under a customer
 * of another business, and the detector's wider reach.
 *
 *  - `customer_identity_repairs`: one row per repair batch. The key is derived from the plan's content (the
 *    rows it would move, per target business), so the same data proposes the same key, and data that no longer
 *    crosses proposes nothing. A batch is `applied` when every split is done and `verified` only after ownership
 *    and the business-scoped reads were checked (A3 point 10).
 *  - `customer_merge_reversals`: the lineage of each split (A3 points 5 and 7). The original `customer_merges`
 *    row is never touched; a reversal is a separate, append-only row (the database refuses UPDATE and DELETE)
 *    naming the merge it reverses (when one explains the rows), the customer the rows came from, the customer
 *    they went to, and every moved row id. It also records what was deliberately NOT moved: business-less
 *    memories held for a person (point 8) and consent rows a person must re-record (point 9).
 *  - `cross_business_quarantines.kind` admits the three kinds item 39 adds: `participant`, `message` and
 *    `identity_conflict` (an open W1 conflict row).
 *
 * Additive only. Down drops the two tables and restores the old CHECK, refusing when a flag of a new kind or a
 * reversal exists (evidence is never dropped; the pre-migration backup is the way back).
 */
return new class extends Migration
{
    private const KINDS = ['merge', 'merge_pointer', 'identity', 'order', 'conversation', 'memory', 'consent_event', 'consent_state', 'participant', 'message', 'identity_conflict'];

    private const OLD_KINDS = ['merge', 'merge_pointer', 'identity', 'order', 'conversation', 'memory', 'consent_event', 'consent_state'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('identity repair needs PostgreSQL (append-only triggers, jsonb).');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE customer_identity_repairs (
                id bigserial PRIMARY KEY,
                key varchar(64) NOT NULL UNIQUE,
                business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                status varchar(24) NOT NULL CHECK (status IN ('applying', 'partial', 'applied', 'verified', 'verification_failed')),
                plan jsonb NOT NULL,
                report jsonb NULL,
                reason text NOT NULL,
                applied_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                correlation_id varchar(64) NULL,
                started_at timestamptz(0) NOT NULL,
                applied_at timestamptz(0) NULL,
                verified_at timestamptz(0) NULL,
                created_at timestamptz(0) NULL,
                updated_at timestamptz(0) NULL,
                CONSTRAINT customer_identity_repairs_verified_check CHECK ((status = 'verified') = (verified_at IS NOT NULL))
            );
            CREATE INDEX customer_identity_repairs_business_index ON customer_identity_repairs (business_id, status);

            CREATE TABLE customer_merge_reversals (
                id bigserial PRIMARY KEY,
                repair_id bigint NOT NULL REFERENCES customer_identity_repairs (id) ON DELETE RESTRICT,
                split_key varchar(64) NOT NULL,
                merge_id bigint NULL REFERENCES customer_merges (id) ON DELETE RESTRICT,
                from_customer_id bigint NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
                from_business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                to_customer_id bigint NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
                to_business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                to_customer_created boolean NOT NULL DEFAULT false,
                moved jsonb NOT NULL,
                withheld jsonb NOT NULL,
                fingerprint varchar(64) NOT NULL,
                reason text NOT NULL,
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz(0) NOT NULL DEFAULT now(),
                CONSTRAINT customer_merge_reversals_split_unique UNIQUE (repair_id, split_key),
                CONSTRAINT customer_merge_reversals_businesses_differ CHECK (from_business_id <> to_business_id)
            );
            CREATE INDEX customer_merge_reversals_from_index ON customer_merge_reversals (from_customer_id);
            CREATE INDEX customer_merge_reversals_to_index ON customer_merge_reversals (to_customer_id);
            CREATE INDEX customer_merge_reversals_merge_index ON customer_merge_reversals (merge_id);

            CREATE OR REPLACE FUNCTION hq_customer_merge_reversal_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'customer_merge_reversals is append-only (% refused): a correction is a new reversal row.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER customer_merge_reversals_no_update BEFORE UPDATE ON customer_merge_reversals
                FOR EACH ROW EXECUTE FUNCTION hq_customer_merge_reversal_immutable();
            CREATE TRIGGER customer_merge_reversals_no_delete BEFORE DELETE ON customer_merge_reversals
                FOR EACH ROW EXECUTE FUNCTION hq_customer_merge_reversal_immutable();
        SQL);

        DB::statement('ALTER TABLE cross_business_quarantines DROP CONSTRAINT cross_business_quarantines_kind_check');
        DB::statement("ALTER TABLE cross_business_quarantines ADD CONSTRAINT cross_business_quarantines_kind_check CHECK (kind IN ('".implode("', '", self::KINDS)."'))");
    }

    public function down(): void
    {
        $reversals = DB::table('customer_merge_reversals')->count();
        if ($reversals > 0) {
            throw new RuntimeException("Refusing to roll back: {$reversals} merge reversal(s) are recorded, and dropping them loses lineage. Restore the pre-migration backup instead (docs/126).");
        }
        $newKinds = DB::table('cross_business_quarantines')->whereNotIn('kind', self::OLD_KINDS)->count();
        if ($newKinds > 0) {
            throw new RuntimeException("Refusing to roll back: {$newKinds} quarantine flag(s) carry a kind the old CHECK cannot hold. Restore the pre-migration backup instead (docs/126).");
        }

        DB::statement('ALTER TABLE cross_business_quarantines DROP CONSTRAINT cross_business_quarantines_kind_check');
        DB::statement("ALTER TABLE cross_business_quarantines ADD CONSTRAINT cross_business_quarantines_kind_check CHECK (kind IN ('".implode("', '", self::OLD_KINDS)."'))");

        DB::unprepared(
            'DROP TRIGGER IF EXISTS customer_merge_reversals_no_delete ON customer_merge_reversals;'
            .' DROP TRIGGER IF EXISTS customer_merge_reversals_no_update ON customer_merge_reversals;'
            .' DROP FUNCTION IF EXISTS hq_customer_merge_reversal_immutable();'
        );
        Schema::dropIfExists('customer_merge_reversals');
        Schema::dropIfExists('customer_identity_repairs');
    }
};
