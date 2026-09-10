<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation measures six dimensions, not one number (ADR-030).
 *
 * `autonomy_grants.min_agreement_rate` has read a single agreement rate since Phase 2.5. That number
 * averages model reasoning, tool selection, policy correctness, enforcement, execution and
 * postcondition together - six things that fail for different reasons, are fixed by different
 * people, and matter differently depending on what is being unlocked.
 *
 * ⚠ Every column here is additive and nullable or defaulted. An existing case keeps working and is
 * read as `tool_selection`, which is what the seeded safety corpus actually measures; an existing
 * grant keeps reading the overall rate, because `min_agreement_dimension` null means "the overall
 * number", exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            // reasoning|tool_selection|policy|enforcement|execution|outcome
            $table->string('dimension', 24)->default('tool_selection')->after('severity');
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            // Denormalised from the case at write time. A result must stay readable as evidence
            // after its case has been re-tagged, or the history says something the run did not.
            $table->string('dimension', 24)->nullable()->after('grader');
            $table->index(['run_id', 'dimension']);
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            // {dimension: {passed, failed, rate}} - the per-dimension evidence a grant can require.
            $table->jsonb('dimension_rates')->nullable()->after('agreement_rate');
        });

        Schema::table('autonomy_grants', function (Blueprint $table) {
            // Null keeps the existing meaning: the overall agreement rate. Naming a dimension makes
            // the grant depend on evidence in the dimension that matters for what it unlocks.
            $table->string('min_agreement_dimension', 24)->nullable()->after('min_agreement_rate');
        });
    }

    public function down(): void
    {
        Schema::table('autonomy_grants', fn (Blueprint $t) => $t->dropColumn('min_agreement_dimension'));
        Schema::table('evaluation_runs', fn (Blueprint $t) => $t->dropColumn('dimension_rates'));
        Schema::table('evaluation_results', function (Blueprint $t) {
            $t->dropIndex(['run_id', 'dimension']);
            $t->dropColumn('dimension');
        });
        Schema::table('evaluation_cases', fn (Blueprint $t) => $t->dropColumn('dimension'));
    }
};
