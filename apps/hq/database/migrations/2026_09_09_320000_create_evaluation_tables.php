<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evaluation subsystem (ADR-023, Phase 2.5 D).
 *
 * `docs/48` listed an evaluations table in M1's dependency detail and it was never built. Phases 9
 * and 10 plan to widen autonomy using "evaluation data from Phases 2 to 6", and nothing in Phases 2
 * to 6 was recording any. The consequence is concrete: without this, autonomy could only ever be
 * widened by opinion, and every argument this project has made about earning trust through
 * measurement would be unsupported by anything measurable.
 *
 * ⭐ **Agreement rate is the currency**: the measured rate at which a reviewing human agrees with
 * what the agent proposed. `autonomy_grants.min_agreement_rate` reads it, which is the mechanical
 * link between "we measured" and "it may act".
 *
 * ⛔ **The safety corpus is permanent.** A suite marked `is_permanent` may not be deleted, and a
 * regression in it is a release blocker rather than a ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_suites', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->string('kind', 24)->default('quality'); // safety|quality|regression
            $table->boolean('is_permanent')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('pass_threshold', 4, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('evaluation_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suite_id')->constrained('evaluation_suites')->cascadeOnDelete();
            $table->string('key', 96);
            $table->text('purpose')->nullable();

            /** What the case feeds in: prompt, conversation, context blocks with their provenance. */
            $table->jsonb('input');

            /**
             * What SHOULD happen, in the vocabulary the control plane already speaks:
             * {"tool_calls":[...], "refusal":true, "refusal_reason":"...", "citations":[...],
             *  "outcome":"...", "must_not_call":["..."]}
             *
             * Expected refusals matter as much as expected answers. A corpus that only checks
             * correct answers cannot notice a model that became helpful about the wrong things.
             */
            $table->jsonb('expectation');

            $table->string('grader', 24)->default('deterministic'); // deterministic|rubric
            $table->jsonb('grader_config')->nullable();
            $table->string('severity', 16)->default('normal'); // blocker|normal|advisory
            $table->jsonb('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['suite_id', 'key']);
        });

        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('suite_id')->constrained('evaluation_suites')->cascadeOnDelete();

            /**
             * ⭐ The versions in force, stamped on the RUN. Without these a regression can be
             * noticed but never attributed, and "something got worse last week" is not a finding
             * anybody can act on.
             */
            $table->string('model', 96)->nullable();
            $table->string('model_tier', 16)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('skill_version', 64)->nullable();
            $table->string('policy_version', 64)->nullable();
            $table->string('autonomy_level', 4)->nullable();

            $table->string('agent_key', 64)->nullable();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('channel', 24)->nullable();

            $table->string('status', 16)->default('running'); // running|passed|failed|error
            $table->unsignedInteger('cases_total')->default(0);
            $table->unsignedInteger('cases_passed')->default(0);
            $table->unsignedInteger('cases_failed')->default(0);
            $table->decimal('agreement_rate', 4, 3)->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);

            $table->foreignId('compared_to_run_id')->nullable()->constrained('evaluation_runs')->nullOnDelete();
            $table->jsonb('regressions')->nullable();
            $table->string('triggered_by', 32)->default('manual'); // manual|ci|scheduler|release
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['suite_id', 'created_at']);
            $table->index(['agent_key', 'status']);
        });

        Schema::create('evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('evaluation_runs')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('evaluation_cases')->cascadeOnDelete();
            $table->boolean('passed');
            $table->decimal('score', 4, 3)->nullable();
            $table->string('grader', 24);
            $table->jsonb('expected')->nullable();
            $table->jsonb('actual')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            /** Provenance of the context this case was graded against (ADR-024): labels, not text. */
            $table->jsonb('context_provenance')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_results');
        Schema::dropIfExists('evaluation_runs');
        Schema::dropIfExists('evaluation_cases');
        Schema::dropIfExists('evaluation_suites');
    }
};
