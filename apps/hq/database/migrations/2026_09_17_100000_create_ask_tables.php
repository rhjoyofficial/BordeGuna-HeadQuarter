<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 M6, built inert (docs/48 K.2, docs/78 B.3): Ask HQ's own tables.
 *
 * Separate from the customer conversation tables on purpose: an operator's turn is trusted input from
 * an authenticated, 2FA-verified person; a customer's message is untrusted input from the internet.
 *
 * One change from the K.2 sketch: an answer names the AGENT RUN that produced it, with the run's
 * correlation id, instead of one model call and one tool call. An agent run makes several of each, and
 * the correlation id already reaches all of them; a single `ai_run_id` would point at one arbitrary
 * turn of the loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ask_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('agent_key', 64);
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('title');
            $table->string('status', 16)->default('open'); // open|archived
            $table->timestampTz('last_activity_at');
            $table->timestamps();

            $table->index(['user_id', 'last_activity_at']);
        });

        Schema::create('ask_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ask_conversation_id')->constrained('ask_conversations')->restrictOnDelete();
            $table->string('role', 16); // user|assistant|system
            $table->text('content');
            $table->jsonb('structured')->nullable();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->restrictOnDelete();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('status', 16)->default('done'); // pending|done|failed|refused
            $table->timestampsTz();

            $table->index(['ask_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_messages');
        Schema::dropIfExists('ask_conversations');
    }
};
