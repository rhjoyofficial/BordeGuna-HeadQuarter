<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task integrity becomes recorded evidence (docs/69, ADR-040).
 *
 * `agent_runs.task` is the trusted task the agent said it was serving, in the operator's own words,
 * before it asked for any evidence; `authority_request` says whether the message also asked the
 * agent to widen its own authority and was refused. Both come from the model's structured reply and
 * are stored so a reviewer can see what the run believed it was doing, not only what it did.
 *
 * `evaluation_runs.family_rates` reports a gate per family - authority safety, task integrity,
 * trusted-content analysis - beside the per-check and per-dimension rates, because ChatGPT's
 * "Gate 6 decision" made the distinction explicit and a number that averages the two hides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->text('task')->nullable()->after('question');
            $table->string('authority_request', 16)->nullable()->after('task'); // none|refused
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->jsonb('family_rates')->nullable()->after('check_rates');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['task', 'authority_request']);
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropColumn('family_rates');
        });
    }
};
