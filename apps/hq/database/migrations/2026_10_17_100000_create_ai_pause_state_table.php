<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/138 ruling 7, docs/141: the Pause All AI state, one row for the whole group.
 *
 * Its own table, not a feature flag, on purpose:
 *   - a missing flag row reads as OFF (`Features::enabled`), which for a pause would mean "not paused":
 *     fail open. Here a missing row reads as PAUSED (`AiPause`), fail closed;
 *   - flags are switched by `hq.flags.manage` through one toggle route that turns a flag either way, so
 *     the pause route would also be a re-enable route. Here pausing and re-enabling are two operations
 *     with two permissions;
 *   - flags are per environment and per business and are cached per request; this is one group-wide
 *     row read fresh at every model and agent tool call.
 *
 * The row is created here as NOT paused, so deploying this changes nothing until a person pauses.
 * History is in `audit_logs` (`ai.paused`, `ai.resumed`); the row holds only the current state and who
 * last moved it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pause_state', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('paused');
            $table->timestampTz('paused_at')->nullable();
            $table->foreignId('paused_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('pause_reason')->nullable();
            $table->timestampTz('resumed_at')->nullable();
            $table->foreignId('resumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resume_reason')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // One row, ever: the state is group-wide, so there is nothing to choose between.
        DB::statement('ALTER TABLE ai_pause_state ADD CONSTRAINT ai_pause_state_single_row CHECK (id = 1)');

        DB::table('ai_pause_state')->insert(['id' => 1, 'paused' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pause_state');
    }
};
