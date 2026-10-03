<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/95 §5 "Platform" and §16 NOW item 4: event-triggered scheduled jobs fire.
 *
 * A run an event started names the event. The unique (job, event) pair is what makes a replayed or
 * redelivered event harmless: the second claim for the same event finds the row and creates nothing,
 * exactly as the (job, scheduled_for) index does for a clock slot. Clock runs leave it null, and
 * PostgreSQL treats nulls as distinct in a unique index, so nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_runs', function (Blueprint $table) {
            $table->uuid('event_id')->nullable()->after('trigger');
            $table->unique(['scheduled_job_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('job_runs', function (Blueprint $table) {
            $table->dropUnique(['scheduled_job_id', 'event_id']);
            $table->dropColumn('event_id');
        });
    }
};
