<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3, the inert half (docs/72 §E).
 *
 * `conversations.state` is the doc-14 state machine; every existing thread opens as `human_required`,
 * which is where every thread lands while the support agent is paused. `ai_disabled` is the per-thread
 * off switch an operator holds. `language` is the script the customer wrote in. `platform_account_id`
 * is the page or number the thread arrived on, so an outbound reply knows which account would send it.
 *
 * `message_deliveries` and `shadow_comparisons` are created empty. The first records that a provider
 * confirmed a send (ADR-031: a 200 is not a postcondition); the second pairs what HQ proposed with
 * what the n8n bot actually sent during the shadow week (docs/22). Neither is written to before the
 * gate that needs it.
 *
 * The feature flag `channels.meta.inbound` is seeded OFF here rather than in a seeder, because the
 * flag being present and off is part of the release, not part of the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('state', 24)->default('human_required')->after('status');
            $table->boolean('ai_disabled')->default(false)->after('state');
            $table->string('language', 12)->nullable()->after('channel');
            $table->foreignId('platform_account_id')->nullable()->after('brand_id')->constrained('platform_accounts')->nullOnDelete();
            $table->index(['business_id', 'state', 'last_message_at']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('conversation_messages')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('provider_ref', 191)->nullable();
            $table->string('status', 16)->default('queued'); // queued|accepted|delivered|failed
            $table->timestampTz('verified_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'attempt']);
        });

        Schema::create('shadow_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('hq_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('external_message_ref', 191)->nullable();
            $table->string('hq_digest', 64);
            $table->string('external_digest', 64)->nullable();
            $table->timestampTz('compared_at')->nullable();
            $table->string('verdict', 24)->nullable(); // same|better|worse|different|unmeasured
            $table->timestamps();

            $table->index(['conversation_id', 'compared_at']);
        });

        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'channels.meta.inbound', 'environment' => '*'],
            [
                'description' => 'Accept Meta (Messenger) webhooks into the conversation inbox. OFF until gate P3-G3.',
                'enabled' => false,
                'rollout' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_comparisons');
        Schema::dropIfExists('message_deliveries');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'state', 'last_message_at']);
            $table->dropConstrainedForeignId('platform_account_id');
            $table->dropColumn(['state', 'ai_disabled', 'language']);
        });

        DB::table('feature_flags')->where('key', 'channels.meta.inbound')->delete();
    }
};
