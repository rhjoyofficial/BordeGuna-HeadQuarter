<?php

use App\Decisions\DecisionBusinessBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruling docs/120 items 21 and 34: an additive `decision_records.business_id`.
 *
 * Backfilled ONLY from a reliable business context (`DecisionBusinessBackfill`): the context row the record
 * names (an approval by id, an agent run or a conversation by uuid) exists and itself carries a business.
 * Anything else (the context row is gone, it carries no business, the context type is unknown) stays NULL and
 * is listed by `hq:decisions:report --unassigned`. The business is never guessed, and a NULL never widens a
 * read: a restricted reader sees only records of one of their businesses.
 *
 * Purely additive. Rollback drops the column; the context rows it was derived from are untouched, so a
 * re-run derives the same values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decision_records', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('user_id')->constrained('businesses')->restrictOnDelete();
            $table->index(['business_id', 'created_at']);
        });

        (new DecisionBusinessBackfill)->run();
    }

    public function down(): void
    {
        Schema::table('decision_records', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
