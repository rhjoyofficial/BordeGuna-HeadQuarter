<?php

namespace Tests\Support\Completion;

use App\Models\Approval;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures for the docs/145 tests (HQ completion, agent M4). Static helpers, not Pest functions, so the
 * files share them without a global-function redeclare in the one-process full suite.
 */
final class M4
{
    public static function on(string ...$flags): void
    {
        foreach ($flags as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
        }
        app(Features::class)->forget();
    }

    /** Puts a user into exactly these businesses (by slug). */
    public static function limit(User $user, string ...$slugs): User
    {
        foreach ($slugs as $slug) {
            UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', $slug)->value('id'), 'access' => 'read']);
        }

        return $user->fresh();
    }

    /** Pause All AI as a person would, through the state row (fail-closed reader). */
    public static function pause(bool $paused = true): void
    {
        DB::table('ai_pause_state')->updateOrInsert(['id' => 1], ['paused' => $paused, 'updated_at' => now()]);
    }

    /**
     * One agent proposal for a tool in a business, decided as `$status`, and when `$outcome` is given, run
     * with that tool-call outcome. The shape the gateway leaves behind, written directly.
     */
    public static function proposal(int $businessId, string $toolKey, string $status, ?string $outcome = null, string $agent = 'executive'): Approval
    {
        $callId = null;
        if ($outcome !== null) {
            $callId = ToolCall::query()->create([
                'tool_key' => $toolKey, 'tool_version' => '1.0.0', 'risk_tier' => 'r3', 'caller_kind' => 'agent', 'actor_type' => 'agent',
                'actor_label' => "agent:{$agent}", 'agent_key' => $agent, 'business_id' => $businessId, 'correlation_id' => (string) Str::uuid(),
                'input' => [], 'status' => $outcome, 'attempt' => 1,
            ])->id;
        }

        return Approval::query()->create([
            'tool_key' => $toolKey, 'tool_version' => '1.0.0', 'payload' => ['n' => (string) Str::uuid()], 'payload_hash' => hash('sha256', (string) Str::uuid()),
            'risk_tier' => 'r3', 'action_class' => 'approval_required', 'business_id' => $businessId, 'agent_key' => $agent,
            'status' => $status, 'resulting_tool_call_id' => $callId, 'consumed_at' => $callId !== null ? now() : null,
        ]);
    }
}
