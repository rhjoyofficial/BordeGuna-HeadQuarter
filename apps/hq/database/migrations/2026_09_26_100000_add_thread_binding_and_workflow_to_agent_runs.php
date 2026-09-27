<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/90 A.1 steps 3 and 5 (stream Y). Two nullable columns on `agent_runs`, additive:
 *
 * - `conversation_uuid`: the thread a Support run was woken on, bound by the code that woke it and
 *   never by the model. Every Support tool that names a conversation is bound to it by the runtime,
 *   so the model cannot switch threads, read another customer's thread, reply elsewhere, or leave the
 *   thread out and let a tool guess. Null on every run that is not a thread run (the Executive,
 *   Analytics, Ask HQ).
 * - `support_workflow`: the workflow the runtime held the run to (`App\Conversation\SupportWorkflow`):
 *   `silent_handover`, `greeting_first` or `context_first`, with the hand-over kind, the rules
 *   version, who decided and why. Null on runs made before it existed and on runs that are not
 *   thread runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('conversation_uuid', 64)->nullable()->after('brand_id');
            $table->jsonb('support_workflow')->nullable()->after('role_boundary');
            $table->index('conversation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['conversation_uuid']);
            $table->dropColumn(['conversation_uuid', 'support_workflow']);
        });
    }
};
