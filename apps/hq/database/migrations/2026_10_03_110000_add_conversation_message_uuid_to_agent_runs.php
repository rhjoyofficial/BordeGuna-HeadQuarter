<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/95 §5 and §16 item 4 (stream W2a): the live wake-up. One nullable column on `agent_runs`, additive:
 *
 * - `conversation_message_uuid`: the inbound customer message a Support run was woken BY, set by the
 *   wake-up job and never by the model. It is the idempotency key of the live path: one run per inbound
 *   message, whatever the webhook, the event drain or the queue redelivers. Null on every run that was
 *   not woken by a message (the gate, the console, Ask HQ, the Executive).
 *
 * `agent_runs.trigger` is a plain string with no CHECK constraint; the wake-up records `conversation`
 * there (`App\Conversation\WakeUp\SupportWakeUp::TRIGGER`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('conversation_message_uuid', 64)->nullable()->after('conversation_uuid');
            $table->index(['trigger', 'conversation_message_uuid'], 'agent_runs_trigger_message_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex('agent_runs_trigger_message_index');
            $table->dropColumn('conversation_message_uuid');
        });
    }
};
