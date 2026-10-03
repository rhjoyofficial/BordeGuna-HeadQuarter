<?php

namespace App\Http\Controllers\Inbox;

use App\Context\Provenance;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\EscalationTriggers;
use App\Conversation\HandoffStateMachine;
use App\Conversation\HandoverReason;
use App\Conversation\InvalidTransition;
use App\Conversation\ShadowComparisons;
use App\Conversation\SupportContext;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Memory\MemoryKind;
use App\Memory\MemoryStore;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DecisionRecord;
use App\Models\FactsOrder;
use App\Models\Memory;
use App\Models\ShadowComparison;
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
        if (! in_array($filter, ['needs_person', 'mine', 'open', 'all'], true)) {
            $filter = 'needs_person';
        }

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
            'threads' => $threads->map(fn (Conversation $c) => $this->card($c, $lastMessages[$c->id] ?? null))->values(),
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

        return Inertia::render('inbox/show', [
            'thread' => $this->card($conversation, null) + [
                'ai_disabled' => $conversation->ai_disabled,
                'platform_account' => $conversation->platformAccount?->name,
                'customer_id' => $conversation->customer_id,
                'allowed_next' => array_map(fn (ConversationState $s) => $s->value, $conversation->state->allowedNext()),
            ],
            'messages' => $messages,
            'proposals' => $this->proposalsFor($conversation),
            // docs/95 stream W2a, the context package: why this thread needs a person, from the latest
            // hand-over the state machine audited, and the customer's recent order facts (HQ's own data).
            'handover' => $this->handoverFor($conversation),
            'orders' => $this->ordersFor($conversation),
            // What is remembered about this customer (docs/78 B.1): shown with its source and date,
            // because memory is never truth (ADR-033) and a reader must be able to weigh it.
            'memory' => $this->memoryFor($conversation),
            // P3-G3's pairing (docs/78 B.1): what the agent proposed beside what actually went out.
            'comparisons' => $this->comparisonsFor($conversation),
            'verdicts' => ShadowComparisons::VERDICTS,
            'memory_keys' => self::MEMORY_KEYS,
            'can_remember' => $this->canRemember($conversation),
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

    /**
     * The structured facts a person may remember about a customer from a thread, by key. A short,
     * fixed list on purpose: a key is a promise about what the value means, and a free key would let
     * one operator's "notes" be another's "address".
     */
    public const MEMORY_KEYS = ['delivery_notes', 'preferred_language', 'preferred_contact_time', 'about'];

    /**
     * A person remembers a fact about the customer, after the thread is resolved (docs/72 §B,
     * docs/78 B.1). Structured memory supersedes its key; an episode is never edited. Provenance is
     * `business` (a colleague's own words), the conversation and the person are recorded on the row,
     * and the act is audited. The agent never writes memory in Phase 3; this is the only writer.
     */
    public function remember(Request $request, Conversation $conversation, MemoryStore $memory): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        if (! $this->canRemember($conversation)) {
            throw ValidationException::withMessages(['kind' => 'A fact is remembered on a resolved or closed thread with a known customer.']);
        }

        $data = $request->validate([
            'kind' => ['required', 'in:structured,episodic'],
            'key' => ['required_if:kind,structured', 'nullable', 'string', 'in:'.implode(',', self::MEMORY_KEYS)],
            'value' => ['required_if:kind,structured', 'nullable', 'string', 'max:500'],
            'content' => ['required_if:kind,episodic', 'nullable', 'string', 'max:1000'],
        ]);

        $scope = ['customer_id' => $conversation->customer_id, 'business_id' => $conversation->business_id];
        $attributes = [
            'conversation_id' => $conversation->id,
            'source_ref' => 'user:'.$request->user()?->getKey(),
            'data_class' => 'personal',
        ];

        $row = $data['kind'] === 'structured'
            ? $memory->remember((string) $data['key'], ['text' => trim((string) $data['value'])], Provenance::Business, $scope, $attributes)
            : $memory->recordEpisode(trim((string) $data['content']), Provenance::Business, $scope, $attributes + ['occurred_at' => now()]);

        $this->auditor->record('memory.remembered', $row, null,
            ['kind' => $row->kind->value, 'key' => $row->key, 'customer_id' => $conversation->customer_id, 'conversation_id' => $conversation->id],
            'remembered by a person on the inbox', $request->user()?->getKey());

        return back()->with('status', 'remembered');
    }

    /**
     * A person closes a structured memory: the value stops being current, the row stays (ADR-033: a
     * store that can be made to forget silently is a store that can be made to lie). An episode
     * cannot be closed here; deletion on a customer's request is its own command (docs/09).
     */
    public function forget(Request $request, Conversation $conversation, Memory $memory): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        if ($memory->customer_id !== $conversation->customer_id || $memory->kind !== MemoryKind::Structured || $memory->valid_to !== null) {
            throw ValidationException::withMessages(['memory' => 'Only a current structured memory of this customer can be closed here.']);
        }

        $memory->forceFill(['valid_to' => now()])->save();
        $this->auditor->record('memory.closed', $memory, ['valid_to' => null], ['valid_to' => $memory->valid_to?->toIso8601String()],
            'closed by a person on the inbox', $request->user()?->getKey());

        return back()->with('status', 'forgotten');
    }

    /** A person's verdict on one pairing of a proposal with what was actually sent (P3-G3). */
    public function verdict(Request $request, Conversation $conversation, ShadowComparison $comparison, ShadowComparisons $comparisons): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        if ($comparison->conversation_id !== $conversation->id || $comparison->verdict === 'unmeasured') {
            throw ValidationException::withMessages(['verdict' => 'That comparison is not on this thread, or nothing was sent to compare.']);
        }
        $data = $request->validate(['verdict' => ['required', 'in:'.implode(',', ShadowComparisons::VERDICTS)]]);

        $comparisons->verdict($comparison, $data['verdict'], (int) $request->user()->getKey());

        return back()->with('status', 'verdict-recorded');
    }

    /** @return list<array<string, mixed>> */
    private function comparisonsFor(Conversation $conversation): array
    {
        return ShadowComparison::query()
            ->where('conversation_id', $conversation->id)
            ->with(['agentRun:id,proposals,created_at', 'externalMessage:id,body,sent_at'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ShadowComparison $c) => [
                'id' => $c->id,
                'run_id' => $c->agent_run_id,
                'proposed' => $c->agentRun === null ? null : ShadowComparisons::proposalText($c->agentRun),
                'sent' => $c->externalMessage?->body,
                'sent_at' => $c->externalMessage?->sent_at?->toIso8601String(),
                'identical' => $c->external_digest !== null && $c->hq_digest === $c->external_digest,
                'verdict' => $c->verdict,
                'compared_at' => $c->compared_at?->toIso8601String(),
            ])->values()->all();
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

        $outcome = $this->send($request, $conversation, $data['text']);

        if (! $outcome->ok() && ($data['keep_as_note'] ?? false)) {
            $this->conversations->recordInternal($conversation, '[draft reply, not sent] '.$data['text'], ['user_id' => $request->user()?->getKey()]);
        }

        return back()->with([
            'status' => $outcome->ok() ? 'reply-sent' : 'reply-not-sent',
            'outcome' => ['status' => $outcome->status->value, 'error' => $outcome->error ?? $outcome->policyReason],
        ]);
    }

    /**
     * A person decides an agent proposal (docs/72 §C): send as it is, send edited, or discard. Each is
     * a `DecisionRecord` with the proposal beside what was finally done, which is the shadow evidence
     * gate P3-G2 reads. A send goes through the gateway like the composer and, in this milestone,
     * fails there; the proposal then stays open, because a decision that could not be carried out
     * is not a decision the run can be closed on.
     */
    public function decide(Request $request, Conversation $conversation, AgentRun $run): RedirectResponse
    {
        $this->assertReadable($request, $conversation);
        $data = $request->validate([
            'decision' => ['required', 'in:send,discard'],
            'text' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $proposal = collect((array) $run->proposals)->first(fn ($p) => is_array($p) && ($p['kind'] ?? null) === 'proposal' && ($p['tool'] ?? null) === 'messaging.send_reply');
        if ($run->agent_key !== ConversationService::SUPPORT_AGENT || ! self::runIsOnThread($run, $conversation) || $proposal === null) {
            abort(404);
        }
        if ($run->reviewed_at !== null) {
            throw ValidationException::withMessages(['decision' => 'This proposal has already been decided.']);
        }

        $proposed = (string) data_get($proposal, 'args.text', '');
        $final = $data['decision'] === 'send' ? (string) ($data['text'] ?? $proposed) : null;
        $outcome = $final === null ? null : $this->send($request, $conversation, $final);
        $carriedOut = $data['decision'] === 'discard' || ($outcome?->ok() ?? false);

        DecisionRecord::create([
            'agent_key' => $run->agent_key,
            'user_id' => $request->user()?->getKey(),
            'context_type' => 'conversation',
            'context_id' => $conversation->uuid,
            'proposal' => ['run_id' => $run->id, 'text' => $proposed, 'why' => $proposal['why'] ?? null],
            'final' => ['decision' => $data['decision'], 'text' => $final, 'edited' => $final !== null && $final !== $proposed],
            'delta' => $final !== null && $final !== $proposed ? ['from' => $proposed, 'to' => $final] : null,
            'decision' => $data['decision'],
            'reason' => $data['reason'] ?? null,
            'outcome' => $outcome === null ? ['status' => 'discarded'] : ['status' => $outcome->status->value, 'error' => $outcome->error ?? $outcome->policyReason],
            'correlation_id' => $run->correlation_id,
        ]);

        if ($carriedOut) {
            $run->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()?->getKey(),
                'review_verdict' => $data['decision'] === 'send' ? 'sent' : 'discarded',
                'review_note' => $data['reason'] ?? null,
            ])->save();
        }

        return back()->with([
            'status' => $data['decision'] === 'discard' ? 'proposal-discarded' : ($carriedOut ? 'reply-sent' : 'reply-not-sent'),
            'outcome' => $outcome === null ? null : ['status' => $outcome->status->value, 'error' => $outcome->error ?? $outcome->policyReason],
        ]);
    }

    private function send(Request $request, Conversation $conversation, string $text): ToolOutcome
    {
        return $this->gateway->execute(ToolRequest::forOperator(
            $request->user(),
            'messaging.send_reply',
            ['business_slug' => $conversation->business?->slug, 'conversation_uuid' => $conversation->uuid, 'text' => $text],
            $conversation->business_id,
        ));
    }

    /** ⚠ 404, not 403 (see the agent screen): a thread on a business you may not see does not exist for you. */
    /** A fact is remembered once the thread is settled and there is a customer to remember it about. */
    private function canRemember(Conversation $conversation): bool
    {
        return $conversation->customer_id !== null
            && in_array($conversation->state, [ConversationState::HumanResolved, ConversationState::Closed], true);
    }

    /** @return list<array<string, mixed>> */
    private function memoryFor(Conversation $conversation): array
    {
        if ($conversation->customer_id === null) {
            return [];
        }

        return Memory::query()
            ->where('customer_id', $conversation->customer_id)
            ->where(fn ($q) => $q->where('business_id', $conversation->business_id)->orWhereNull('business_id'))
            ->whereNull('valid_to')
            ->orderByDesc('id')
            ->limit(SupportContext::MEMORY_LIMIT)
            ->get()
            ->map(fn (Memory $m) => [
                'id' => $m->id,
                'kind' => $m->kind->value,
                'key' => $m->key,
                'text' => $m->kind === MemoryKind::Structured ? (string) (data_get($m->value, 'text') ?? json_encode($m->value)) : (string) $m->content,
                'provenance' => $m->provenance->value,
                'source' => $m->source_ref,
                'at' => ($m->kind === MemoryKind::Episodic ? $m->occurred_at : $m->valid_from)?->toIso8601String(),
                'closable' => $m->kind === MemoryKind::Structured,
            ])->values()->all();
    }

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

    /**
     * Proposals the support agent made on this thread that a person has not decided yet: the shadow
     * the P3-G2 gate reviews. Read from agent_runs, never from a message, because a proposal is not a
     * message until a person sends it.
     *
     * @return list<array<string, mixed>>
     */
    private function proposalsFor(Conversation $conversation): array
    {
        return AgentRun::query()
            ->where('agent_key', ConversationService::SUPPORT_AGENT)
            ->where(fn ($q) => $q->where('conversation_uuid', $conversation->uuid)->orWhere('correlation_id', $conversation->uuid))
            ->whereNull('reviewed_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->flatMap(fn (AgentRun $run) => collect((array) $run->proposals)
                ->filter(fn ($p) => is_array($p) && ($p['kind'] ?? null) === 'proposal' && ($p['tool'] ?? null) === 'messaging.send_reply')
                ->take(1)
                ->map(fn ($p) => ['run_id' => $run->id, 'text' => (string) data_get($p, 'args.text', ''), 'why' => $p['why'] ?? null, 'at' => $run->created_at?->toIso8601String()]))
            ->values()
            ->all();
    }

    /**
     * A run is on this thread when the runtime bound it there (`conversation_uuid`, docs/90 A.1 step 3)
     * or, for the rows written before the binding existed, when its correlation id is the thread's.
     */
    private static function runIsOnThread(AgentRun $run, Conversation $conversation): bool
    {
        return $run->conversation_uuid === $conversation->uuid || $run->correlation_id === $conversation->uuid;
    }

    /**
     * Why this thread needs a person (docs/95 stream W2a): the latest `conversation.state_changed` the
     * state machine audited into `human_required`, with its reason as a code and as the sentence. A thread
     * that OPENED needing a person made no transition; it is reported as the agent being paused, which is
     * the only way a thread opens there. Null when the thread is not waiting on a person.
     *
     * @return array{reason: ?string, label: ?string, detail: ?string, from: ?string, at: ?string, by: ?string}|null
     */
    private function handoverFor(Conversation $conversation): ?array
    {
        if ($conversation->state !== ConversationState::HumanRequired) {
            return null;
        }

        $row = AuditLog::query()
            ->where('action', 'conversation.state_changed')
            ->where('target_type', $conversation->getMorphClass())
            ->where('target_id', (string) $conversation->getKey())
            ->where('after->to', ConversationState::HumanRequired->value)
            ->orderByDesc('id')
            ->first();

        $reasonText = $row === null ? EscalationTriggers::REASON_AGENT_PAUSED : $row->reason;
        $reason = HandoverReason::fromText($reasonText);

        return [
            'reason' => $reason?->value,
            'label' => $reason?->label(),
            'detail' => $reasonText,
            'from' => $row === null ? null : (((array) $row->after)['from'] ?? null),
            'at' => ($row?->occurred_at ?? $conversation->opened_at)?->toIso8601String(),
            'by' => $row === null || $row->actor_type !== 'human' ? null : (User::query()->find($row->actor_id)?->email ?? $row->actor_label),
        ];
    }

    /**
     * The known customer's last orders, as HQ's fact table holds them (docs/95 stream W2a): the five the
     * agent is also shown (`SupportContext::RECENT_ORDERS`). Empty for a stranger or an unlinked thread.
     *
     * @return list<array{ref: string, store: ?string, status: string, payment_status: ?string, placed_at: ?string, gross: string}>
     */
    private function ordersFor(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        if ($customer === null) {
            return [];
        }

        return FactsOrder::query()
            ->where('customer_id', $customer->id)
            ->with('store:id,name')
            ->orderByDesc('placed_at')
            ->limit(SupportContext::RECENT_ORDERS)
            ->get(['id', 'order_ref', 'store_id', 'status', 'payment_status', 'gross', 'placed_at'])
            ->map(fn (FactsOrder $o) => [
                'ref' => $o->order_ref,
                'store' => $o->store?->name,
                'status' => $o->status,
                'payment_status' => $o->payment_status,
                'placed_at' => $o->placed_at?->toIso8601String(),
                'gross' => (string) $o->gross,
            ])
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
