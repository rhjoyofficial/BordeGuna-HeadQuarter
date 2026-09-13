<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\DecisionRecord;
use App\Models\ToolCall;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agent screen, and the shadow review that the seven-day period actually consists of.
 *
 * ⛔ **There is no endpoint here that starts a run.** The browser can read runs and record a verdict
 * on one; it cannot ask a question. That is deliberate for the length of the shadow period: `Ask HQ`
 * is M6, and putting an agent in front of the operator before seven days of reviewed runs would
 * hand it the trust the period exists to establish. Runs are started from the console by
 * `hq:agent:ask`, which is a decision somebody makes rather than a button somebody clicks.
 *
 * ⛔ **The browser also cannot activate, pause or re-tool an agent.** Those are `hq.agents.manage`
 * decisions that change what an agent may do, and M5 leaves them where every other capability
 * change lives: a seeder, a migration, or a console command somebody runs deliberately.
 *
 * ⭐ **A review writes a `decision_records` row.** That table has existed and been empty since Phase
 * 0, and this is the first thing to write to it. It is the alignment corpus: what was proposed, what
 * the human made of it, and why - which is what a later evaluation replays to measure whether a
 * prompt change made things better or worse.
 */
final class AgentController extends Controller
{
    /**
     * The same three words `decision_records.decision` already uses.
     *
     * ⚠ A parallel vocabulary was the obvious mistake here: "agreed / edited / rejected" reads
     * slightly better for a shadow review, and would have meant the alignment corpus held two words
     * for one idea and every later query had to know both.
     */
    private const VERDICTS = ['approved', 'edited', 'rejected'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly BusinessScope $businessScope,
    ) {}

    /**
     * The runs this human may read.
     *
     * ⛔ **A run carries the model's own claims, and a claim quotes figures.** Reading somebody
     * else's business's run is therefore reading their numbers, which is why this is scoped rather
     * than left to `hq.agents.view` alone - `brand_manager` holds that permission.
     *
     * ⚠ A run with a NULL business is a cross-business question. It is group-wide by nature, so a
     * restricted user does not see it. Fail closed: showing it because it names no single business
     * is the inversion that would leak the most.
     *
     * Null scope means unrestricted, which is today's only operator (V.11), so nothing changes now.
     */
    private function readable(Request $request): Builder
    {
        $query = AgentRun::query();
        $scope = ($user = $request->user()) === null ? [] : $this->businessScope->all($user);

        return $scope === null ? $query : $query->whereIn('business_id', $scope);
    }

    public function index(Request $request): Response
    {
        $agents = Agent::with('enablingUser:id,email')->orderBy('key')->get()->map(fn (Agent $a) => [
            'key' => $a->key,
            'name' => $a->name,
            'purpose' => $a->purpose,
            'status' => $a->status,
            'role_key' => $a->role_key,
            'model_tier' => $a->model_tier,
            'max_autonomy_level' => $a->max_autonomy_level?->value,
            'allowed_tools' => (array) ($a->allowed_tools ?? []),
            'enabling_user' => $a->enablingUser?->email,
            // Counted over what this human may READ, not over the table. A count is a disclosure.
            'runs' => $this->readable($request)->where('agent_key', $a->key)->count(),
            'awaiting_review' => $this->readable($request)->where('agent_key', $a->key)
                ->where('shadow', true)->whereNull('reviewed_at')
                ->whereIn('status', ['answered', 'refused', 'clarified', 'stopped'])->count(),
        ]);

        $filter = (string) $request->query('filter', 'all');

        $runs = $this->readable($request)
            ->when($filter === 'unreviewed', fn ($q) => $q->whereNull('reviewed_at')->where('shadow', true))
            ->when($filter !== 'all' && $filter !== 'unreviewed', fn ($q) => $q->where('agent_key', $filter))
            ->latest('id')
            ->limit(60)
            ->get();

        // One grouped query rather than a sum per row: `ai_runs` is the cost ledger and this screen
        // reads it rather than keeping a second copy that could disagree with it.
        $costs = AiRun::query()
            ->whereIn('correlation_id', $runs->pluck('correlation_id'))
            ->groupBy('correlation_id')
            ->selectRaw('correlation_id, sum(estimated_cost_usd) as cost')
            ->pluck('cost', 'correlation_id');

        return Inertia::render('agents/index', [
            'agents' => $agents,
            'runs' => $runs->map(fn (AgentRun $r) => $this->card($r, (float) ($costs[$r->correlation_id] ?? 0))),
            'filter' => $filter,
            'can' => [
                'review' => $request->user()?->can('hq.agents.manage') ?? false,
            ],
        ]);
    }

    public function show(Request $request, AgentRun $run): Response
    {
        $this->assertReadable($request, $run);

        $calls = ToolCall::where('correlation_id', $run->correlation_id)
            ->orderBy('id')
            ->get(['id', 'tool_key', 'status', 'policy_result', 'policy_reason', 'duration_ms', 'created_at']);

        $models = AiRun::where('correlation_id', $run->correlation_id)
            ->orderBy('id')
            ->get(['id', 'provider', 'model', 'model_tier', 'status', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'latency_ms', 'attempt']);

        return Inertia::render('agents/show', [
            'run' => $this->card($run, (float) $models->sum('estimated_cost_usd')) + [
                'evidence' => (array) $run->evidence,
                'proposals' => (array) $run->proposals,
                'autonomy_constraints' => (array) $run->autonomy_constraints,
                // Labels and digests, never the text (ADR-024).
                'context_provenance' => (array) $run->context_provenance,
                'error' => $run->error,
                'review_note' => $run->review_note,
                'prompt_version' => $run->prompt_version,
            ],
            'tool_calls' => $calls,
            'model_calls' => $models,
            'can' => [
                'review' => $request->user()?->can('hq.agents.manage') ?? false,
            ],
        ]);
    }

    /**
     * Record what a human made of a shadow run.
     *
     * ⚠ A verdict is not editable and not deletable through this screen. It is a dated statement
     * about what somebody thought at the time, and an alignment corpus that can be revised after the
     * fact measures the revision rather than the judgement.
     */
    public function review(Request $request, AgentRun $run): RedirectResponse
    {
        // Reviewing a run is also reading it, and a verdict writes the run's answer into the
        // alignment corpus. Both need the same gate.
        $this->assertReadable($request, $run);

        $data = $request->validate([
            'verdict' => 'required|string|in:'.implode(',', self::VERDICTS),
            'note' => 'nullable|string|max:2000',
        ]);

        if ($run->reviewed_at !== null) {
            return back()->withErrors(['verdict' => 'This run has already been reviewed, and a review is not revised after the fact.']);
        }

        $run->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->getKey(),
            'review_verdict' => $data['verdict'],
            'review_note' => $data['note'] ?? null,
        ])->save();

        DecisionRecord::create([
            'agent_key' => $run->agent_key,
            'user_id' => $request->user()?->getKey(),
            'context_type' => 'agent_run',
            'context_id' => (string) $run->uuid,
            'proposal' => ['answer' => $run->answer, 'proposals' => (array) $run->proposals],
            'final' => ['verdict' => $data['verdict'], 'note' => $data['note'] ?? null],
            'decision' => $data['verdict'],
            'reason' => $data['note'] ?? null,
            'outcome' => ['status' => $run->status, 'stop_reason' => $run->stop_reason, 'autonomy_level' => $run->autonomy_level?->value],
            'correlation_id' => $run->correlation_id,
        ]);

        $this->auditor->record('agent.run_reviewed', $run, after: [
            'agent' => $run->agent_key,
            'verdict' => $data['verdict'],
            'autonomy_level' => $run->autonomy_level?->value,
            'status' => $run->status,
        ], reason: $data['note'] ?? null, actorUserId: $request->user()?->getKey());

        return back();
    }

    /**
     * ⚠ 404, not 403. A restricted user learning that run 412 exists and belongs to a business they
     * cannot see is itself a disclosure, and the useful cases are indistinguishable to them anyway.
     */
    private function assertReadable(Request $request, AgentRun $run): void
    {
        if (! $this->readable($request)->whereKey($run->getKey())->exists()) {
            abort(404);
        }
    }

    private function card(AgentRun $run, float $cost): array
    {
        return [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'agent_key' => $run->agent_key,
            'question' => $run->question,
            'task' => $run->task,
            'canonical_task' => $run->canonical_task,
            'task_grounding' => $run->task_grounding,
            'authority_request' => $run->authority_request,
            'answer' => $run->answer,
            'status' => $run->status,
            'stop_reason' => $run->stop_reason,
            'shadow' => $run->shadow,
            'autonomy_level' => $run->autonomy_level?->value,
            'autonomy_reason' => $run->autonomy_reason,
            'turns' => $run->turns,
            'tool_calls_count' => $run->tool_calls_count,
            'duration_ms' => $run->duration_ms,
            'model' => $run->model,
            'cost_usd' => round($cost, 4),
            'trigger' => $run->trigger,
            'reviewed_at' => $run->reviewed_at?->toIso8601String(),
            'review_verdict' => $run->review_verdict,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
