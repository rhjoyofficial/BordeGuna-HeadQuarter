<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/90 A.1 step 1: the model's raw structured reply, kept beside every run as evidence.
 *
 * P3-G2 failed on twenty cases whose cause could only be inferred, because the run kept the PARSED
 * reply and not the words the model wrote: whether the model omitted its arguments or the runner
 * dropped them was a question the evidence could not answer. One row per accepted or rejected reply:
 * the raw text (secret-named keys masked, structure and every other key exactly as written), the
 * decoded object, the normalised tool calls the runtime read from it, and the identity of the call.
 *
 * ⛔ Diagnostic only, never an authorization source: nothing in the application reads a row back into
 *    a decision, and a test holds that. It rides on `ai_runs`' retention: a run that is pruned takes
 *    its replies with it, and a run that is kept (an agent's, an evaluation's) keeps them.
 *
 * Additive: one new table, nothing else touched. Historical runs stay without a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_run_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->string('correlation_id', 128)->nullable()->index();
            $table->string('agent_key', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 96)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            // final | repaired | invalid | truncated: which reply of the attempt this is.
            $table->string('kind', 16);
            $table->string('stop_reason', 32)->nullable();
            $table->text('raw_text');
            $table->jsonb('raw_json')->nullable();
            // What the runtime read from it (docs/90 A.1 step 2), kept apart from the raw words.
            $table->jsonb('normalised')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['ai_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_replies');
    }
};
