<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/140 (ruling docs/138 section 5 P3-W1, docs/131 ruling 3): the human, audited resolution of an open
 * `customer_identity_conflicts` row.
 *
 * `customer_identity_conflict_resolutions` holds one row per resolved conflict (UNIQUE conflict_id, so a repeat is a
 * replay, never a second decision). It is append-only: the database refuses UPDATE and DELETE, a correction is a new
 * conflict and a new resolution. It records which of the three choices the person made (`kept_owner`,
 * `moved_to_claimant`, `not_resolvable`), the identity's owner before and after, whether a quarantined identity was
 * released, the evidence the person stated together with what HQ checked, the reason, the restrictions carried to a
 * new owner, the person and the correlation id. `business_id` is the conflict's own, and the CHECK holds that a move
 * never changes it (a resolution never crosses a business; the composite key on customer_identities holds the rest).
 *
 * Additive. Down drops the table only while it is empty; once a person has resolved anything, dropping it would lose
 * the record of who decided what, and the way back is the pre-deploy backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('identity conflict resolutions need PostgreSQL (append-only triggers, jsonb).');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE customer_identity_conflict_resolutions (
                id bigserial PRIMARY KEY,
                conflict_id bigint NOT NULL REFERENCES customer_identity_conflicts (id) ON DELETE RESTRICT,
                business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                identity_id bigint NOT NULL REFERENCES customer_identities (id) ON DELETE RESTRICT,
                resolution varchar(24) NOT NULL CHECK (resolution IN ('kept_owner', 'moved_to_claimant', 'not_resolvable')),
                owner_before_customer_id bigint NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
                owner_after_customer_id bigint NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
                released_from_quarantine boolean NOT NULL DEFAULT false,
                evidence jsonb NOT NULL,
                reason text NOT NULL,
                carried_restrictions jsonb NOT NULL DEFAULT '[]'::jsonb,
                resolved_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                correlation_id varchar(64) NULL,
                created_at timestamptz(0) NOT NULL DEFAULT now(),
                CONSTRAINT customer_identity_conflict_resolutions_conflict_unique UNIQUE (conflict_id),
                CONSTRAINT customer_identity_conflict_resolutions_owner_check CHECK (
                    (resolution = 'moved_to_claimant') = (owner_before_customer_id <> owner_after_customer_id)
                )
            );
            CREATE INDEX customer_identity_conflict_resolutions_business_index ON customer_identity_conflict_resolutions (business_id, created_at);
            CREATE INDEX customer_identity_conflict_resolutions_identity_index ON customer_identity_conflict_resolutions (identity_id);

            CREATE OR REPLACE FUNCTION hq_identity_conflict_resolution_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'customer_identity_conflict_resolutions is append-only (% refused): a correction is a new conflict and a new resolution.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER customer_identity_conflict_resolutions_no_update BEFORE UPDATE ON customer_identity_conflict_resolutions
                FOR EACH ROW EXECUTE FUNCTION hq_identity_conflict_resolution_immutable();
            CREATE TRIGGER customer_identity_conflict_resolutions_no_delete BEFORE DELETE ON customer_identity_conflict_resolutions
                FOR EACH ROW EXECUTE FUNCTION hq_identity_conflict_resolution_immutable();
        SQL);
    }

    public function down(): void
    {
        $recorded = (int) DB::table('customer_identity_conflict_resolutions')->count();
        if ($recorded > 0) {
            throw new RuntimeException("Refusing to roll back: {$recorded} identity conflict resolution(s) are recorded, and dropping them loses who decided what. "
                .'Restore the pre-deploy backup instead (docs/140).');
        }

        DB::unprepared(
            'DROP TABLE customer_identity_conflict_resolutions;'
            .' DROP FUNCTION IF EXISTS hq_identity_conflict_resolution_immutable();'
        );
    }
};
