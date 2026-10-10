<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/117 (ruling docs/110 §5 (m)): quarantine flags for relationships that cross a business boundary
 * (pre-guard cross-business merges and every row they, or an unscoped identity lookup, left hanging under
 * a customer of another business), and the human reviews of those flags.
 *
 * Additive only. Both tables are append-only: a review is a new row, never an edit of the flag, and the
 * rows they point at (customer_merges, identities, orders...) are never modified by anything here. The
 * subject is polymorphic by table name, so no foreign key cascades into these rows from a subject table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cross_business_quarantines', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);          // merge|merge_pointer|identity|order|conversation|memory|consent_event|consent_state
            $table->string('subject_table', 48);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('customer_business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('row_business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('merge_id')->nullable()->constrained('customer_merges')->restrictOnDelete();
            $table->string('reason', 64);
            $table->jsonb('detail')->nullable();
            $table->timestampTz('detected_at');
            $table->foreignId('flagged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['subject_table', 'subject_id', 'reason'], 'cross_business_quarantines_subject_unique');
            $table->index('customer_id');
            $table->index('merge_id');
        });

        Schema::create('cross_business_quarantine_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quarantine_id')->constrained('cross_business_quarantines')->restrictOnDelete();
            $table->string('decision', 24);      // confirmed_wrong|needs_repair|disputed
            $table->text('note');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['quarantine_id', 'id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE cross_business_quarantines ADD CONSTRAINT cross_business_quarantines_kind_check CHECK (kind IN ('merge', 'merge_pointer', 'identity', 'order', 'conversation', 'memory', 'consent_event', 'consent_state'))");
        DB::statement("ALTER TABLE cross_business_quarantine_reviews ADD CONSTRAINT cross_business_quarantine_reviews_decision_check CHECK (decision IN ('confirmed_wrong', 'needs_repair', 'disputed'))");
        DB::statement("ALTER TABLE cross_business_quarantine_reviews ADD CONSTRAINT cross_business_quarantine_reviews_note_present CHECK (length(btrim(note)) > 0)");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hq_cross_business_quarantine_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only (% refused). Record a new review; never edit a flag or a review.', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cross_business_quarantines_no_update BEFORE UPDATE ON cross_business_quarantines
                FOR EACH ROW EXECUTE FUNCTION hq_cross_business_quarantine_immutable();
            CREATE TRIGGER cross_business_quarantines_no_delete BEFORE DELETE ON cross_business_quarantines
                FOR EACH ROW EXECUTE FUNCTION hq_cross_business_quarantine_immutable();
            CREATE TRIGGER cross_business_quarantine_reviews_no_update BEFORE UPDATE ON cross_business_quarantine_reviews
                FOR EACH ROW EXECUTE FUNCTION hq_cross_business_quarantine_immutable();
            CREATE TRIGGER cross_business_quarantine_reviews_no_delete BEFORE DELETE ON cross_business_quarantine_reviews
                FOR EACH ROW EXECUTE FUNCTION hq_cross_business_quarantine_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS cross_business_quarantine_reviews_no_delete ON cross_business_quarantine_reviews;'
                .' DROP TRIGGER IF EXISTS cross_business_quarantine_reviews_no_update ON cross_business_quarantine_reviews;'
                .' DROP TRIGGER IF EXISTS cross_business_quarantines_no_delete ON cross_business_quarantines;'
                .' DROP TRIGGER IF EXISTS cross_business_quarantines_no_update ON cross_business_quarantines;'
                .' DROP FUNCTION IF EXISTS hq_cross_business_quarantine_immutable();'
            );
        }

        Schema::dropIfExists('cross_business_quarantine_reviews');
        Schema::dropIfExists('cross_business_quarantines');
    }
};
