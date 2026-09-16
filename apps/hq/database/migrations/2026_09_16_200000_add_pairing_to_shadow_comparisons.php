<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HQ side of the P3-G3 pairing (docs/78 B.1): a comparison names the run whose proposal it
 * holds and the external message it was compared with, and a verdict names who gave it and when.
 * The digests stay; the texts stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shadow_comparisons', function (Blueprint $table) {
            $table->foreignId('agent_run_id')->nullable()->after('conversation_id')->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('external_message_id')->nullable()->after('external_message_ref')->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('verdict_by')->nullable()->after('verdict')->constrained('users')->nullOnDelete();
            $table->timestampTz('verdict_at')->nullable()->after('verdict_by');
            $table->index(['agent_run_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shadow_comparisons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verdict_by');
            $table->dropColumn('verdict_at');
            $table->dropConstrainedForeignId('external_message_id');
            $table->dropConstrainedForeignId('agent_run_id');
        });
    }
};
