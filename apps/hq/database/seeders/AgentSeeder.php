<?php

namespace Database\Seeders;

use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The two Phase 2 agents (docs/48 H.2, H.3).
 *
 * ⛔ **Both are seeded PAUSED, and that is the shipped state.** `ActingIdentity::holds()` requires an
 * active agent, so a paused one can do nothing at all: the release arrives inert and Joy starts the
 * shadow period deliberately rather than by deploying. It is also the documented rollback - "set
 * both agents paused; nothing else is affected" - so the off switch is proven by being the default.
 *
 * ⛔ **Neither holds a single write tool.** Every key below is R1 or R2 and read-only. That is not a
 * limitation of the first release, it is its shape: the value in Phase 2 is "tell me what is
 * happening across four systems, with evidence", which needs no write capability, and shipping it
 * without one means the first weeks of agent operation build an evaluation record before anything
 * can go wrong.
 *
 * ⚠ **No autonomy grant is created, deliberately.** `AutonomyResolver` treats an unmatched
 * combination as A0, so the shadow period requires no grant to exist - the safe state is the absence
 * of configuration rather than the presence of the right configuration. A grant is what will later
 * be needed to reach A1, and it will be a decision somebody makes, on evidence, in its own change.
 *
 * ⚠ `enabling_user_id` is the owner. An agent borrows a human's authority and has none of its own,
 * so an agent with nobody named is inert by construction. If no user exists yet, the row is created
 * without one and stays inert until somebody is named - which is the right failure.
 */
class AgentSeeder extends Seeder
{
    /**
     * Everything HQ can read about itself, the shops and the ERP.
     *
     * `erp.poll_overview` and `erp.poll_low_stock` are deliberately ABSENT even though they sound
     * like reads: both are R3 writes that replace a snapshot row, and an agent should not be able to
     * mutate a read model as a side effect of curiosity. It reads the snapshot instead; the
     * scheduler refreshes it.
     */
    private const READ_EVERYTHING = [
        'facts.query_orders',
        'facts.snapshot_freshness',
        'erp.read_snapshot',
        'erp.run_report',
        'commerce.list_orders',
        'commerce.check_fact_integrity',
        'hq.list_approvals',
        'hq.list_jobs',
        'hq.recent_events',
    ];

    /**
     * The analytics agent's set, from `docs/48` H.3.
     *
     * ⚠ It has the data tools and not the operational ones (`hq.list_*`, `hq.recent_events`). Those
     * answer "what needs attention", which is the executive agent's declared job. It was tempting to
     * add `hq.list_jobs` so analytics could say "that snapshot is stale BECAUSE this job has been
     * failing" - a harmless R1 read - but the spec draws the line here and a judgement call is not a
     * reason to move somebody else's line.
     */
    private const ANALYTICS_TOOLS = [
        'facts.query_orders',
        'facts.snapshot_freshness',
        'erp.read_snapshot',
        'erp.run_report',
        'commerce.list_orders',
        'commerce.check_fact_integrity',
    ];

    /**
     * The support agent's set (docs/72 §B): what it needs to answer a customer from facts, plus the
     * thread itself and the ONE write it may propose. `messaging.send_reply` is R3 and customer-facing,
     * so at A0 the runner records a proposal and runs nothing (`AgentRunner::hasEffect`); and with no
     * messaging provider registered it cannot execute for anyone at all.
     */
    private const SUPPORT_TOOLS = [
        'facts.query_orders',
        'facts.snapshot_freshness',
        'commerce.list_orders',
        'conversation.read_thread',
        'messaging.send_reply',
    ];

    public function run(): void
    {
        $owner = User::query()->oldest('id')->first();

        $this->agent('executive', [
            'name' => 'Executive',
            'purpose' => 'The cross-business picture and what needs a person today: approvals waiting, jobs failing, '
                .'events that never delivered, integrity discrepancies, and anything that has moved against its own pattern.',
            'role_key' => 'executive',
            'allowed_tools' => self::READ_EVERYTHING,
            // HIGH is worth the cost here and nowhere else in Phase 2: the output is a decision aid
            // for the person who runs the group.
            'model_tier' => 'high',
            'enabling_user_id' => $owner?->getKey(),
        ]);

        $this->agent('analytics', [
            'name' => 'Analytics',
            'purpose' => 'KPIs, sales and customer trends, anomalies and comparisons, with the coverage behind every '
                .'figure stated and a refusal when the data is too thin to support the question.',
            'role_key' => 'analytics',
            'allowed_tools' => self::ANALYTICS_TOOLS,
            'model_tier' => 'medium',
            'enabling_user_id' => $owner?->getKey(),
        ]);

        // Phase 3 (docs/72). ⛔ Seeded PAUSED at A0 with NO enabling user, unlike the other two: it is
        // the first agent that would speak to a person who is not Joy, and naming its enabling human
        // is the deliberate act that gate P3-G2 begins with, not a side effect of a deploy.
        $this->agent('support', [
            'name' => 'Support',
            'purpose' => 'Answers a customer\'s question on their own thread, in their own language, from the order facts and '
                .'the thread itself; proposes exactly one reply for a person to send; hands the thread to a person the moment '
                .'money, a complaint or a request for a human appears.',
            'role_key' => 'support',
            'allowed_tools' => self::SUPPORT_TOOLS,
            'model_tier' => 'medium',
            'enabling_user_id' => null,
        ]);
    }

    private function agent(string $key, array $attributes): void
    {
        $existing = Agent::where('key', $key)->first();

        // ⚠ Re-running must not resurrect an agent somebody paused, and must not widen a tool list
        // somebody narrowed. Only the descriptive fields are refreshed; status, autonomy and the
        // tool grant are set once, at creation, and belong to whoever administers them afterwards.
        if ($existing !== null) {
            $existing->forceFill([
                'name' => $attributes['name'],
                'purpose' => $attributes['purpose'],
                'role_key' => $attributes['role_key'],
            ])->save();

            return;
        }

        Agent::create($attributes + [
            'key' => $key,
            'status' => 'paused',
            'max_autonomy_level' => AutonomyLevel::A0,
            'business_scope' => null,   // every business its enabling human can see
            'brand_scope' => null,
            'memory_scope' => null,
        ]);
    }
}
