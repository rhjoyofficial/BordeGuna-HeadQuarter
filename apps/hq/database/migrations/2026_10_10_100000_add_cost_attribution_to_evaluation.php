<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation cost attribution (docs/106, ruling docs/103 §2).
 *
 * `evaluation_runs.cost_usd` existed from the first evaluation migration and nothing ever wrote it, so
 * every run, P3-G2 attempt 5 included, recorded the column default 0.000000 while its model calls cost
 * real money. The cost is now attributed from the `ai_runs` evidence by `EvaluationCostAttributor`.
 *
 * - `evaluation_results.correlation_id`: the agent run's correlation, the key every `ai_runs` row of the
 *   case (each turn, each retry and fallback attempt) carries. `ai_run_id` names one of them only.
 * - `evaluation_runs.cost_estimated_usd`: the sum of HQ's own estimates. NULL = never attributed.
 * - `evaluation_runs.cost_provider_usd`: the sum of provider-reported costs over the calls that have
 *   one. NULL = no call carries a provider figure (today, always: nothing writes `actual_cost_usd`).
 *   Kept apart from the estimate, never filled from it.
 * - `evaluation_runs.cost_attribution`: how the number was reached (method, counts, completeness).
 *
 * ⚠ No data is touched here. Historical runs keep their 0 until `hq:evaluation:attribute-cost --apply`
 *   recomputes them from existing evidence, and only where that evidence is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('ai_run_id')->index();
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->decimal('cost_estimated_usd', 12, 6)->nullable()->after('cost_usd');
            $table->decimal('cost_provider_usd', 12, 6)->nullable()->after('cost_estimated_usd');
            $table->jsonb('cost_attribution')->nullable()->after('cost_provider_usd');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropColumn(['cost_estimated_usd', 'cost_provider_usd', 'cost_attribution']);
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn('correlation_id');
        });
    }
};
