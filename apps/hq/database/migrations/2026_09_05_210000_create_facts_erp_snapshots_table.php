<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read model (ADR-007) for what the scheduler last pulled from the ERP. One row per (business, kind),
 * replaced on every successful poll; `as_of` is the ERP's own timestamp so a stale snapshot is
 * visibly stale. Never a source of truth: the ERP is, and any screen shows `as_of` beside the figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facts_erp_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('kind', 40);              // overview | low_stock
            $table->jsonb('payload');
            $table->timestampTz('as_of')->nullable();  // ERP-reported
            $table->timestampTz('polled_at');          // when HQ fetched it
            $table->string('job_run_ref', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['business_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts_erp_snapshots');
    }
};
