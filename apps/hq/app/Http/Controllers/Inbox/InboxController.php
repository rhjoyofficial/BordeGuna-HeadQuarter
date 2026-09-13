<?php

namespace App\Http\Controllers\Inbox;

use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Conversation\InvalidTransition;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DecisionRecord;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator inbox (docs/72 §C): every customer thread a person may read, and the handoff actions.
 *
 * ⛔ Reads are scoped by `BusinessScope`, and a thread outside the scope is 404, not 403, as the agent
 * screen does it: learning that a thread exists on a business you may not see is itself a disclosure.
 *
 * ⛔ There is no endpoint here that sends a message directly. The composer goes through the gateway
 * tool `messaging.send_reply`, under the operator's own identity, with consent, permission, the
 * outbound policy and the capability check in front of it. In this milestone that call fails for
 * everyone because no messaging provider is registered, and the screen says so.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly BusinessScope $businessScope,
        private readonly HandoffStateMachine $states,
        private readonly ConversationService $conversations,
        private readonly ToolGateway $gateway,
        private readonly Auditor $auditor,
    ) {}

    private function readable(Request $request): Builder
    {
        $query = Conversation::query();
        $user = $request->user();

        return $user === null ? $query->whereRaw('1 = 0') : $this->businessScope->restrict($query, $user);
    }

    public function index(Request $request): Response
    {
        $filter = (string) $request->query('filter', 'needs_person');

        $threads = $this->readable($request)
            ->with(['business:id,name,slug', 'brand:id,name', 'customer:id,name,primary_phone_canonical,attributes', 'assignee:id,email'])
            ->when($filter === 'needs_person', fn ($q) => $q->where('state', ConversationState::HumanRequired->value))
            ->when($filter === 'mine', fn ($q) => $q->where('assigned_user_id', $request->user()?->getKey()))
            ->when($filter === 'open', fn ($q) => $q->where('status', '!=', 'closed'))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $lastMessages = ConversationMessage::query()
            ->whereIn('conversation_id', $threads->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        return Inertia::render('inbox/index', [
            'threads' => $threads->map(fn (Conversation $c) => $this->card($c, $lastMessages[$c->id] ?? null)),
            'filter' => $filter,
            'counts' => [
                'needs_person' => $this->readable($request)->where('state', ConversationState::HumanRequired->value)->count(),
                'open' => $this->readable($request)->where('status', '!=', 'closed')->count(),
            ],
            'agent' => $this->agentStatus(),
            'can' => ['manage' => $request->user()?->can('hq.inbox.manage') ?? false],
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $this->assertReadable($request, $conversation);
        $conversation->load(['business:id,name,slug', 'brand:id,name', 'customer', 'assignee:id,email', 'platformAccount:id,platform,account_ref,name']);

        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('user:id,email')
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $m) => [
                'uuid' => $m->uuid,
                'direction' => $m->direction,
                'author_type' => $m->author_type,
                'author' => $m->author_type === 'user' ? ($m->user?->email ?? 'a colleague') : ($m->agent_key ?? $m->author_type),
                'provenance' => $m->provenance->value,
                'trusted' => $m->provenance->isTrusted(),
                'body' => $m->body,
                'status' => $m->status,
                'language' => $m->metadata['language'] ?? null,
                'attachments' => $m->metadata['attachments'] ?? [],
                'at' => ($m->received_at ?? $m->sent_at ?? $m->created_at)?->setTimezone($timezone)->toIso8601String(),
            ]);

        // Proposals the agent made on this thread that a person has not decided yet: the shadow the
        // P3-G2 gate reviews. Read from agent_runs, never from a message, because a proposal is not a
        // message until a person sends it.
        $proposals = DecisionRecord::query()->where('context_type', 'conversation')->where('context_id', $conversation->uuid)->exists()
            ? []
            : $this->proposalsFor($conversation);

        return Inertia::render('inbox/show', [
            'thread' => $this->card($conversation, null) + [
                'ai_disabled' => $conversation->ai_disabled,
                'platform_account' => $conversation->platformAccount?->name,
                'customer_id' => $conversation->customer_id,
                'allowed_next' => array_map(fn (ConversationState $s) => $s->value, $conversation->state->allowedNext()),
            ],
            'messages' => $messages,
            'proposals' => $proposals,
            'assignees' => User::query()->get(['id', 'email'])
                ->filter(fn (User $u) => $u->can('hq.inbox.manage') && $this->businessScope->allows($u, $conversation->business_id))
                ->map(fn (User $u) => ['id' => $u->id, 'email' => $u->email])->values(),
            'agent' => $this->agentStatus(),
            'can' => ['manage' => $request->user()?->can('hq.inbox.manage') ?? false],
        ]);
    }

    public function takeOver(Request $request, Conversation $conversation): RedirectResponse
    {
        return $this->move($request, $conversation, ConversationState::HumanActive, 'taken over from the inbox');
    }

    public function resolve(Request $request, Conversation $conversation): RedirectResponse
    {
        return $this->move($request, $conversation, ConversationState::HumanResolved, $request->input('note'));
    }

    public function returnToAgent(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertReadable($request, $conversation);

        if (! $this->agentStatus()['active']) {
            throw ValidationException::withMessages(['state' => 'The support agent is paused, so there is nobody to hand the thread back to.']);
        }
        if ($conversation->ai_disabled) {
            throw ValidationException::withMessages(['state' => 'The agent is switched off for this thread. Turn it on first.']);
        }

        return $this->move($request, $conversation, ConversationState::AiResumed, 'handed back to the agent from the inbox', ConversationService::SUPPORT_AGENT);
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        return $this->move($request, $conversation, ConversationState::Closed, $request->input('reason'));
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $assignee = User::findOrFail((int) $data['user_id']);
        if (! $assignee->can('hq.inbox.manage') || ! $this->businessScope->allows($assignee, $conversation->business_id)) {
            throw ValidationException::withMessages(['user_id' => 'That person cannot work this thread.']);
        }

        $before = $conversation->assigned_user_id;
        $conversation->forceFill(['assigned_user_id' => $assignee->id])->save();
        $this->auditor->record('conversation.assigned', $conversation, ['assigned_user_id' => $before], ['assigned_user_id' => $assignee->id], null, $request->user()?->getKey());

        return back()->with('status', 'assigned');
    }

    public function toggleAi(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertReadable($request, $conversation);

        $to = ! $conversation->ai_disabled;
        $conversation->forceFill(['ai_disabled' => $to])->save();
        $this->auditor->record('conversation.ai_toggled', $conversation, ['ai_disabled' => ! $to], ['ai_disabled' => $to], $request->input('reason'), $request->user()?->getKey());

        // A thread the agent held loses it the moment it is switched off; a person is now needed.
        if ($to && $conversation->state->canMoveTo(ConversationState::HumanRequired) && $conversation->state->owner() === 'ai') {
            $this->states->transition($conversation, ConversationState::HumanRequired, $request->user()?->getKey(), 'the agent was switched off for this thread');
        }

        return back()->with('status', $to ? 'ai-disabled' : 'ai-enabled');
    }

    public function note(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $this->conversations->recordInternal($conversation, $data['body'], ['user_id' => $request->user()?->getKey()]);

        return back()->with('status', 'note-added');
    }

    /**
     * The composer. The text goes through the gateway tool under the operator's own identity; the
     * outcome, whatever it is, is shown. In this milestone it fails: no provider is registered.
     */
    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'keep_as_note' => ['nullable', 'boolean'],
        ]);

        $outcome = $this->gateway->execute(ToolRequest::forOperator(
            $request->user(),
            'messaging.send_reply',
            ['business_slug' => $conversation->business?->slug, 'conversation_uuid' => $conversation->uuid, 'text' => $data['text']],
            $conversation->business_id,
        ));

        if (! $outcome->ok() && ($data['keep_as_note'] ?? false)) {
            $this->conversations->recordInternal($conversation, '[draft reply, not sent] '.$data['text'], ['user_id' => $request->user()?->getKey()]);
        }

        return back()->with([
            'status' => $outcome->ok() ? 'reply-sent' : 'reply-not-sent',
            'outcome' => ['status' => $outcome->status->value, 'error' => $outcome->error ?? $outcome->policyReason],
        ]);
    }

    /** ⚠ 404, not 403 (see the agent screen): a thread on a business you may not see does not exist for you. */
    private function assertReadable(Request $request, Conversation $conversation): void
    {
        if (! $this->readable($request)->whereKey($conversation->getKey())->exists()) {
            abort(404);
        }
    }

    private function move(Request $request, Conversation $conversation, ConversationState $to, ?string $reason, ?string $agentKey = null): RedirectResponse
    {
        $this->assertReadable($request, $conversation);

        try {
            $this->states->transition($conversation, $to, $request->user()?->getKey(), $reason, $agentKey);
        } catch (InvalidTransition $e) {
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }

        return back()->with('status', 'state-'.$to->value);
    }

    /** @return array{active: bool, status: string|null} */
    private function agentStatus(): array
    {
        $status = Agent::query()->where('key', ConversationService::SUPPORT_AGENT)->value('status');

        return ['active' => $status === 'active', 'status' => $status];
    }

    /** @return list<array<string, mixed>> */
    private function proposalsFor(Conversation $conversation): array
    {
        return \App\Models\AgentRun::query()
            ->where('agent_key', ConversationService::SUPPORT_AGENT)
            ->where('correlation_id', $conversation->uuid)
            ->whereNull('reviewed_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->flatMap(fn ($run) => collect((array) $run->proposals)
                ->filter(fn ($p) => is_array($p) && ($p['tool'] ?? null) === 'messaging.send_reply')
                ->map(fn ($p) => ['run_id' => $run->id, 'text' => (string) data_get($p, 'args.text', ''), 'why' => $p['why'] ?? null, 'at' => $run->created_at?->toIso8601String()]))
            ->values()
            ->all();
    }

    private function card(Conversation $c, ?ConversationMessage $last): array
    {
        $customer = $c->customer;
        $stranger = $customer === null || ($customer->primary_phone_canonical === null && (bool) data_get($customer->attributes, 'stranger', false));

        return [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'channel' => $c->channel->value,
            'state' => $c->state->value,
            'state_label' => $c->state->label(),
            'status' => $c->status,
            'language' => $c->language,
            'business' => $c->business?->name,
            'business_slug' => $c->business?->slug,
            'brand' => $c->brand?->name,
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->primary_phone_canonical],
            'stranger' => $stranger,
            'assignee' => $c->assignee?->email,
            'last_message' => $last === null ? null : ['body' => $last->body, 'direction' => $last->direction, 'at' => $last->created_at?->toIso8601String()],
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'opened_at' => $c->opened_at?->toIso8601String(),
        ];
    }
}
