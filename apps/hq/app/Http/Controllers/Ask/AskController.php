<?php

namespace App\Http\Controllers\Ask;

use App\Ask\AskHq;
use App\Http\Controllers\Controller;
use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\Business;
use App\Models\ToolCall;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Ask HQ's screen (docs/48 K, docs/78 B.3), behind the flag `ask_hq` and the permission `hq.ask.use`.
 *
 * ⛔ The only write is a question: words, an agent from a fixed list, a business, a conversation of the
 * person's own. There is no field for a tool, arguments or a model, and anything else a browser sends
 * is dropped by validation before it reaches `AskHq`.
 */
final class AskController extends Controller
{
    public function __construct(private readonly AskHq $ask) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function show(Request $request, AskConversation $conversation): Response
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->getKey(), 404);

        return $this->page($request, $conversation);
    }

    public function store(Request $request, BusinessScope $scope): RedirectResponse
    {
        abort_unless($this->ask->enabled(), 404);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.AskHq::MAX_QUESTION],
            'agent_key' => ['required', 'string', Rule::in($this->ask->askableAgents())],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'conversation' => ['nullable', 'uuid'],
        ]);

        $conversation = null;
        if (! empty($data['conversation'])) {
            $conversation = AskConversation::query()->where('uuid', $data['conversation'])->where('user_id', $request->user()->getKey())->first();
            abort_if($conversation === null, 404);
        }

        try {
            $result = $this->ask->submit($request->user(), $conversation, $data['question'], $data['agent_key'], $data['business_id'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['question' => $e->getMessage()]);
        }

        return redirect()->route('ask.show', $result['conversation'])
            ->with('status', $result['reply']->status === AskMessage::REFUSED ? 'ask-refused' : 'ask-submitted');
    }

    private function page(Request $request, ?AskConversation $conversation): Response
    {
        abort_unless($this->ask->enabled(), 404);
        $user = $request->user();

        $messages = $conversation === null ? [] : $conversation->messages()->with('agentRun:id,uuid,status,shadow,autonomy_level,stop_reason,tool_calls_count')
            ->get()
            ->map(fn (AskMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'status' => $m->status,
                'content' => $m->content,
                'structured' => $m->structured,
                'created_at' => $m->created_at?->toIso8601String(),
                'run' => $m->agentRun === null ? null : [
                    'uuid' => $m->agentRun->uuid,
                    'id' => $m->agentRun->id,
                    'status' => $m->agentRun->status,
                    'shadow' => (bool) $m->agentRun->shadow,
                    'autonomy_level' => $m->agentRun->autonomy_level?->value,
                    'stop_reason' => $m->agentRun->stop_reason,
                    // What HQ actually did for this answer, straight from the gateway's record.
                    'tool_calls' => $m->correlation_id === null ? [] : ToolCall::query()->where('correlation_id', $m->correlation_id)->orderBy('id')
                        ->get(['id', 'tool_key', 'status', 'policy_result', 'duration_ms'])
                        ->map(fn (ToolCall $c) => ['id' => $c->id, 'tool_key' => $c->tool_key, 'status' => $c->status,
                            'policy_result' => $c->policy_result, 'duration_ms' => $c->duration_ms])
                        ->all(),
                ],
            ])->all();

        $businessScope = app(BusinessScope::class)->all($user);

        return Inertia::render('ask/index', [
            'conversation' => $conversation === null ? null : [
                'uuid' => $conversation->uuid,
                'title' => $conversation->title,
                'agent_key' => $conversation->agent_key,
                'business_id' => $conversation->business_id,
            ],
            'messages' => $messages,
            'conversations' => AskConversation::query()->where('user_id', $user->getKey())->latest('last_activity_at')->limit(30)
                ->get(['uuid', 'title', 'agent_key', 'last_activity_at'])
                ->map(fn (AskConversation $c) => ['uuid' => $c->uuid, 'title' => $c->title, 'agent_key' => $c->agent_key,
                    'last_activity_at' => $c->last_activity_at->toIso8601String()])
                ->all(),
            'agents' => collect($this->ask->askableAgents())->map(fn (string $key) => [
                'key' => $key,
                'refusal' => $this->ask->refusalFor($user, $key),
            ])->all(),
            'businesses' => Business::query()->where('is_active', true)
                ->when($businessScope !== null, fn ($q) => $q->whereIn('id', $businessScope))
                ->orderBy('name')->get(['id', 'name'])->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'runtime_on' => $this->ask->runtimeOn(),
            'max_question' => AskHq::MAX_QUESTION,
        ]);
    }
}
