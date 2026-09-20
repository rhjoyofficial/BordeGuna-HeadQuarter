<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 M7 (docs/48 M.4, docs/80 C.1): the stored briefing.
 *
 * "The rendered briefing is stored, so it can be re-read, and so `decision_records` can later link
 * 'he acted on this recommendation' back to the exact briefing that made it." So a row is evidence:
 * written once, never changed, kept forever (`RetentionPolicy::PERMANENT`). It holds the whole
 * evidence bundle as it was collected, the validator's verdict, and the narrative only when every
 * figure in it was traced to the bundle; a rejected narrative is kept inside `narrative_check`, where
 * nothing that reads `narrative` can ship it.
 *
 * `ai_run_id` is the narrator's model call, null while no narrator exists. It points at a retention
 * detail table, so `RetentionPolicy` keeps a model call a briefing names.
 *
 * Additive: one new table, no existing column touched. `down()` drops it and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('capability', 64);
            $table->string('capability_version', 16);
            $table->string('bundle_version', 32);
            $table->date('briefing_date');
            $table->string('timezone', 64);
            // Null is the whole group. `scope_business_ids` is the caller's own restriction at the
            // time, so the page can decide who may open the row without re-reading the bundle.
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->jsonb('scope_business_ids')->nullable();
            $table->string('state', 16); // complete|degraded
            $table->jsonb('degraded_reasons');
            $table->jsonb('bundle')->nullable(); // null only when collection itself failed
            $table->char('bundle_digest', 64)->nullable();
            $table->jsonb('validation');
            $table->string('narration_state', 24); // off|runtime_off|blocked|unavailable|empty|rejected|narrated
            $table->text('narrative')->nullable();
            $table->jsonb('narrative_check')->nullable();
            $table->string('narrator', 64)->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('composed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            // One logical composition is one row: a redelivered call finds the row it already wrote.
            $table->string('idempotency_key', 191)->unique();
            $table->timestampTz('generated_at');
            $table->timestampsTz();

            $table->index(['briefing_date', 'id']);
            $table->index(['business_id', 'briefing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefings');
    }
};
