<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-043 (docs/74): a connector failure is not a refusal, and a model omission is not a group-wide
 * task. `tool_calls.outcome_class` says whose fault a non-success was (control plane, external, HQ);
 * `agent_runs.task_scope` records the targets the runtime read the trusted text to be about, with
 * the resolver version and the phrase that decided it; `evaluation_runs.external_outcomes` tallies
 * the external classes a run met, apart from every pass/fail number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->string('outcome_class', 32)->nullable()->after('failure_class');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('task_scope')->nullable()->after('authority_source');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->jsonb('external_outcomes')->nullable()->after('family_rates');
        });
    }

    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropColumn('outcome_class');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('task_scope');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropColumn('external_outcomes');
        });
    }
};
