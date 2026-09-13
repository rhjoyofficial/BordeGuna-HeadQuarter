<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trusted task apart from the model's interpretation, and a result that measured nothing (docs/71, ADR-041).
 *
 * `agent_runs.canonical_task` is the task the runtime established from the operator's own words, or
 * empty when it established none; `task_grounding` is the one-word reason. `task` (ADR-040) stays as
 * the model's interpretation. `authority_request` widens to hold `in_untrusted_content`.
 *
 * `evaluation_results.unmeasured` marks a case whose run the model provider refused (a quota, not
 * the agent); such a result is excluded from every pass/fail count and `evaluation_runs.cases_unmeasured`
 * says how many there were, so a provider limit can never produce a green gate and never reads as
 * a behavioural failure either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('authority_request', 32)->nullable()->change();
            $table->text('canonical_task')->nullable()->after('task');
            $table->string('task_grounding', 40)->nullable()->after('canonical_task');
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->boolean('unmeasured')->default(false)->after('passed');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->unsignedInteger('cases_unmeasured')->default(0)->after('cases_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['canonical_task', 'task_grounding']);
            $table->string('authority_request', 16)->nullable()->change();
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->dropColumn('unmeasured');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropColumn('cases_unmeasured');
        });
    }
};
