<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations, messages and memory (Phase 2.5 F).
 *
 * ⛔ **A conversation is not keyed by a phone number.** The same person reaches the group on
 * Messenger, then WhatsApp, then by calling, and a thread keyed by a phone number cannot follow
 * them. It is keyed by its own id, linked to a `customer` through the identity model that already
 * exists (`customer_identities` already enumerates messenger_psid, instagram_igsid and whatsapp),
 * and carries the channel plus the platform's own thread reference as attributes rather than as
 * identity.
 *
 * ⚠ `customer_id` is NULLABLE and that is not laziness. The first inbound message from a stranger
 * arrives before anybody knows who they are, and a schema that demands the answer up front forces
 * the caller to invent one.
 *
 * Memory is split three ways on purpose, because the three have different truth conditions:
 * STRUCTURED memory is a fact with a key ("delivery address"), correctable and current; EPISODIC
 * memory is a thing that happened at a time, which stays true forever because it happened; SEMANTIC
 * memory is a generalisation ("prefers calls after 6pm"), which is inferred and may be wrong.
 * Collapsing them produces a store that cannot say whether it is remembering or guessing.
 *
 * ⚠ No embedding column. `pgvector` is created only when the server has it (see the Phase 0
 * migration), so a `vector` column here would make the schema differ between machines that both
 * report a clean migration. Semantic retrieval arrives with the phase that needs it, on a schema
 * that is the same everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('channel', 24);
            /** The platform's own thread id, where it has one. An attribute, never the identity. */
            $table->string('external_thread_ref', 191)->nullable();
            $table->string('subject')->nullable();

            $table->string('status', 16)->default('open');   // open|waiting|closed
            /**
             * Who is answering right now. `none` is the shipped default and the honest one: no
             * agent exists, so nothing is AI-owned in Phase 2.5.
             */
            $table->string('owner', 16)->default('none');    // none|ai|human
            $table->string('handoff_state', 24)->default('none'); // none|requested|accepted|returned
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_agent_key', 64)->nullable();

            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'last_message_at']);
            $table->index(['customer_id', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('participant_type', 16); // customer|user|agent|system
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key', 64)->nullable();
            $table->foreignId('identity_id')->nullable()->constrained('customer_identities')->nullOnDelete();
            $table->string('role', 24)->default('participant');
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'participant_type']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('direction', 12);  // inbound|outbound|internal
            $table->string('author_type', 16); // customer|user|agent|system
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key', 64)->nullable();

            $table->string('channel', 24);
            $table->string('external_message_ref', 191)->nullable();
            $table->text('body')->nullable();

            /**
             * ⛔ Provenance on every message (ADR-024). An inbound customer message is UNTRUSTED,
             * and a block that loses its label on the way into memory is a delayed injection with
             * the label removed.
             */
            $table->string('provenance', 24)->default('customer');
            $table->string('content_digest', 64)->nullable();

            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->unsignedBigInteger('tool_call_id')->nullable();
            $table->string('status', 16)->default('recorded'); // recorded|queued|sent|delivered|read|failed
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['channel', 'external_message_ref']);
        });

        Schema::create('conversation_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('conversation_messages')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['message_id', 'media_asset_id']);
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 16); // structured|episodic|semantic
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('agent_key', 64)->nullable();

            /** Structured memory: a key and a value. Null for the other two kinds. */
            $table->string('key', 96)->nullable();
            $table->jsonb('value')->nullable();

            /** Episodic and semantic memory: prose, with where it came from. */
            $table->text('content')->nullable();

            $table->string('provenance', 24)->default('business');
            $table->string('source_ref')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();

            /** Semantic memory is inferred, so it carries how sure we are and can be wrong. */
            $table->decimal('confidence', 4, 3)->nullable();

            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('memories')->nullOnDelete();

            $table->string('data_class', 24)->default('internal');
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'customer_id']);
            $table->index(['business_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // A platform thread maps to at most one open conversation. Partial, because a thread
            // reference is null for conversations that never had one, and PostgreSQL would
            // otherwise treat every null as distinct and let duplicates through where it matters.
            DB::statement(
                "CREATE UNIQUE INDEX conversations_channel_thread_unique ON conversations (channel, external_thread_ref)
                 WHERE external_thread_ref IS NOT NULL AND status <> 'closed'"
            );

            // Structured memory is a CURRENT fact: one live value per key per subject. Episodic and
            // semantic rows are free to accumulate, because they are records of different moments
            // rather than competing answers to one question.
            DB::statement(
                "CREATE UNIQUE INDEX memories_structured_key_unique ON memories
                 (coalesce(business_id, 0), coalesce(customer_id, 0), coalesce(agent_key, ''), key)
                 WHERE kind = 'structured' AND valid_to IS NULL AND deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS memories_structured_key_unique');
            DB::statement('DROP INDEX IF EXISTS conversations_channel_thread_unique');
        }

        Schema::dropIfExists('memories');
        Schema::dropIfExists('conversation_message_attachments');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
