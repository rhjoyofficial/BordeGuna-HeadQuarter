<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/143: the owner-console items of docs/137 section 6 that are pure HQ logic. Every one ships INERT:
 * the six feature flags are seeded OFF below and no behaviour exists while its flag is off.
 *
 *   do_plans, do_plan_steps       "Do" mode: a plan of gateway tool steps, walked one at a time (Q1)
 *   approval_batches              the record of one batch review act over several approvals (Q8)
 *   owner_brief_deliveries        one row per owner-only Telegram brief delivery (Q10)
 *   reply_option_sets             Support's A/B/C drafts and the one a person chose (Q11)
 *   event_watches, watch_firings  watches created from the chat, proposal-only (Q12)
 *
 * Nothing here is a second approval mechanism or a second record of an effect: approvals stay in
 * `approvals`, effects in `tool_calls`, the rows below point at both.
 */
return new class extends Migration
{
    public const FLAGS = [
        'console.do_mode' => '"Do" mode: plans of approved steps from the console (docs/143 A). OFF until the Phase 9 activation ruling.',
        'console.batch_approval' => 'Batch review of customer-message approvals, one decision and one audit row per message (docs/143 B). OFF.',
        'console.one_click_approve' => 'Approve from a console card after the full payload is shown, same guards as the approval page (docs/143 C). OFF.',
        'console.telegram_brief' => 'Owner-only morning brief on Telegram, an approval-gated tool (docs/143 D). OFF.',
        'support.reply_options' => 'Support proposes up to three reply drafts; a person picks and edits one (docs/143 E). OFF.',
        'console.event_watches' => 'Event watches proposed from the chat; a person enables each; proposals only (docs/143 F). OFF.',
    ];

    public function up(): void
    {
        Schema::create('do_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('title', 200);
            // proposed -> running -> completed | halted | cancelled
            $table->string('state', 16)->index();
            $table->string('proposer_kind', 16); // human | agent
            $table->string('agent_key', 64)->nullable();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id');
            $table->string('workflow_instance_key', 191)->nullable()->unique();
            $table->text('halt_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('do_plan_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('do_plan_id')->constrained('do_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->string('tool_key', 96);
            $table->jsonb('args');
            $table->string('summary', 300);
            // pending -> awaiting_approval | succeeded | failed | rejected | skipped
            $table->string('state', 20);
            $table->boolean('effectful');
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('tool_call_id')->nullable()->constrained('tool_calls')->nullOnDelete();
            $table->jsonb('outcome')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['do_plan_id', 'seq']);
        });

        Schema::create('approval_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('decided_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('requested_count');
            $table->unsignedSmallInteger('executed_count');
            $table->unsignedSmallInteger('refused_count');
            // One entry per message: approval id, payload hash shown, result, reason. Never the message text.
            $table->jsonb('results');
            $table->timestamps();
        });

        Schema::create('owner_brief_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('briefing_id')->constrained('briefings')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            // A name from config, never an address: the chat id is read from configuration at send time.
            $table->string('destination', 32);
            $table->string('state', 16)->index(); // sent | failed | refused
            $table->string('message_digest', 64)->nullable();
            $table->string('provider_ref', 128)->nullable();
            $table->text('error')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reply_option_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->jsonb('options'); // [{label: A, text: ...}], one to three
            $table->string('state', 12)->index(); // open | chosen | discarded
            $table->string('chosen_label', 1)->nullable();
            $table->text('final_text')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('send_status', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('event_watches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('event_type', 96)->index();
            $table->jsonb('conditions'); // [{path, op, value}]
            // null = notify only; otherwise {tool_key, args}: the watch can only PROPOSE it (an approval).
            $table->jsonb('action')->nullable();
            $table->unsignedSmallInteger('max_per_hour');
            $table->unsignedSmallInteger('max_per_day');
            $table->string('state', 12)->index(); // proposed | enabled | disabled | rejected
            $table->string('proposer_kind', 16);
            $table->string('agent_key', 64)->nullable();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('enabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('last_fired_at')->nullable();
            $table->unsignedInteger('fire_count')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_firings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_watch_id')->constrained('event_watches')->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->string('outcome', 24); // proposed | notified | rate_limited | proposal_refused
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->text('detail')->nullable();
            $table->timestamps();
            $table->unique(['event_watch_id', 'event_id']);
        });

        foreach (self::FLAGS as $key => $description) {
            if (! DB::table('feature_flags')->where('key', $key)->where('environment', '*')->exists()) {
                DB::table('feature_flags')->insert([
                    'key' => $key, 'environment' => '*', 'description' => $description,
                    'enabled' => false, 'rollout' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', array_keys(self::FLAGS))->delete();
        foreach (['watch_firings', 'event_watches', 'reply_option_sets', 'owner_brief_deliveries', 'approval_batches', 'do_plan_steps', 'do_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
