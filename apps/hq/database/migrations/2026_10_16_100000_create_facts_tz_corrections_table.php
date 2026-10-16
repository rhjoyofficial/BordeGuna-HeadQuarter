<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/133 (ruling docs/131 section 2 item 8): the append-only log of the time-zone backfill.
 *
 * One row per corrected timestamp: which `facts_orders` row and column, the value stored before and after, the
 * source event and the shop's raw string that prove the correction, the batch key the dry run printed, the person
 * who applied it and why. The database refuses UPDATE and DELETE: a correction of a correction is a new row.
 *
 * Additive only. Down drops the table and refuses when a correction is recorded (evidence is never dropped; the
 * pre-migration backup is the way back).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('the time-zone backfill log needs PostgreSQL (append-only triggers).');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE facts_tz_corrections (
                id bigserial PRIMARY KEY,
                batch_key varchar(64) NOT NULL,
                facts_order_id bigint NOT NULL REFERENCES facts_orders (id) ON DELETE RESTRICT,
                business_id bigint NOT NULL REFERENCES businesses (id) ON DELETE RESTRICT,
                column_name varchar(24) NOT NULL CHECK (column_name IN ('placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at')),
                old_value timestamptz(0) NOT NULL,
                new_value timestamptz(0) NOT NULL,
                source_event_id varchar(64) NOT NULL,
                source_string varchar(64) NOT NULL,
                reason text NOT NULL,
                applied_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz(0) NOT NULL DEFAULT now(),
                CONSTRAINT facts_tz_corrections_changes CHECK (old_value <> new_value),
                CONSTRAINT facts_tz_corrections_once UNIQUE (batch_key, facts_order_id, column_name)
            );
            CREATE INDEX facts_tz_corrections_order_index ON facts_tz_corrections (facts_order_id, column_name);

            CREATE OR REPLACE FUNCTION hq_facts_tz_correction_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'facts_tz_corrections is append-only (% refused): a correction of a correction is a new row.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER facts_tz_corrections_no_update BEFORE UPDATE ON facts_tz_corrections
                FOR EACH ROW EXECUTE FUNCTION hq_facts_tz_correction_immutable();
            CREATE TRIGGER facts_tz_corrections_no_delete BEFORE DELETE ON facts_tz_corrections
                FOR EACH ROW EXECUTE FUNCTION hq_facts_tz_correction_immutable();
        SQL);
    }

    public function down(): void
    {
        $rows = DB::table('facts_tz_corrections')->count();
        if ($rows > 0) {
            throw new RuntimeException("Refusing to roll back: {$rows} time-zone correction(s) are recorded, and dropping them loses the evidence. Restore the pre-migration backup instead (docs/133).");
        }

        DB::unprepared(
            'DROP TRIGGER IF EXISTS facts_tz_corrections_no_delete ON facts_tz_corrections;'
            .' DROP TRIGGER IF EXISTS facts_tz_corrections_no_update ON facts_tz_corrections;'
            .' DROP FUNCTION IF EXISTS hq_facts_tz_correction_immutable();'
        );
        Schema::dropIfExists('facts_tz_corrections');
    }
};
