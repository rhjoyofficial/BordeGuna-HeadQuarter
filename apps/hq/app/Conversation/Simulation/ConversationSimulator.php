<?php

namespace App\Conversation\Simulation;

use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Gateway\Descriptor\Channel;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DomainEvent;
use App\Models\MessageDelivery;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The simulation world (docs/72 addendum): scripted conversations replayed through the real
 * conversation core, with no model and no provider, inside one transaction that is rolled back.
 *
 * ⛔ Deterministic and traceless by default. The replay uses the same `ConversationService::ingest`,
 * the same state machine and the same escalation rules production uses, against two simulated
 * Facebook Pages it creates for the brand it is pointed at, and then rolls everything back: no
 * thread, customer, consent row, audit row or event survives unless `keep` is asked for, which the
 * command refuses on production. A scenario that needs the support agent active flips the agent row
 * for its own replay only; the rollback restores it.
 *
 * ⭐ Three invariants are checked on EVERY scenario, whether or not it names them (docs/77 C.3, from
 * ChatGPT's P3-G1 evidence list of 16 September): every state change the scenario caused has both
 * its audit row and its event (`audited` equals `transitions`); every thread and customer it created
 * belongs to the business that owns the brand (`business_scoped`); and a message whose time the
 * scenario stamped is stored at that instant in UTC (`received_at`, recorded only then, so that a
 * repeated replay compares equal).
 *
 * What it proves: that the core's rules hold on the inputs ChatGPT listed on 13 September (colloquial
 * Bangla, typos, mixed scripts, anger, refunds, a person asked for, prompt injection, a fake owner, a
 * claimed approval, order changes, redelivery, one person on two pages, a page HQ does not hold, a
 * thread through a person's hands and back). What it does not prove: anything about the agent's
 * answers. That is gate P3-G2, and it needs a model and a provider quota.
 */
final class ConversationSimulator
{
    public const PAGES = ['sim-page' => 'Simulated Page', 'sim-page-2' => 'Simulated Page 2'];

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly HandoffStateMachine $states,
    ) {}

    /**
     * Replay every scenario against the brand and roll back, unless `keep`.
     *
     * @param  iterable<Scenario>  $scenarios
     * @return list<SimulationResult>
     */
    public function replay(iterable $scenarios, string $brandCode, bool $keep = false): array
    {
        $brand = Brand::query()->where('code', $brandCode)->first();
        if ($brand === null) {
            throw new RuntimeException("No brand with code [{$brandCode}]; the simulation needs a brand to hang its pages on.");
        }

        DB::beginTransaction();
        try {
            $this->preparePages($brand);
            $results = [];
            foreach ($scenarios as $scenario) {
                $results[] = $this->run($scenario, $brand);
            }

            if ($keep) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $results;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Gate P3-G2's view of one scenario (docs/77 C.4): replay it, read the thread the core left, roll
     * the replay back, and return the thread as plain data.
     *
     * ⛔ Nothing here outlives the call. The support agent runs AFTER this returns, on the copy, so its
     * run, tool calls and cost are written as evidence while no simulated customer, thread or consent
     * survives. `with_agent` is the core's own decision: the thread is the agent's only in `ai_active`
     * or `ai_resumed`, exactly as production would wake it.
     *
     * @return array{scenario: string, core_passed: bool, core_mismatches: list<string>, with_agent: bool, state: ?string,
     *     conversation_uuid: ?string, business_id: ?int, business_slug: ?string, brand_id: ?int, brand_name: ?string,
     *     language: ?string, messages: list<array{uuid: string, body: string, provenance: string, attachments: int}>}
     */
    public function capture(Scenario $scenario, string $brandCode): array
    {
        $brand = Brand::query()->where('code', $brandCode)->first();
        if ($brand === null) {
            throw new RuntimeException("No brand with code [{$brandCode}]; the simulation needs a brand to hang its pages on.");
        }

        DB::beginTransaction();
        try {
            $this->preparePages($brand);
            $result = $this->run($scenario, $brand);
            $conversation = $result->conversationUuid === null
                ? null
                : Conversation::query()->with(['business', 'brand'])->where('uuid', $result->conversationUuid)->first();

            $thread = [
                'scenario' => $scenario->key,
                'core_passed' => $result->passed,
                'core_mismatches' => $result->mismatches,
                'with_agent' => $conversation !== null
                    && in_array($conversation->state, [ConversationState::AiActive, ConversationState::AiResumed], true),
                'state' => $conversation?->state?->value,
                'conversation_uuid' => $conversation?->uuid,
                'business_id' => $conversation?->business_id,
                'business_slug' => $conversation?->business?->slug,
                'brand_id' => $conversation?->brand_id,
                'brand_name' => $conversation?->brand?->name,
                'language' => $conversation?->language,
                'messages' => $conversation === null ? [] : $conversation->messages()
                    ->where('direction', 'inbound')->orderBy('id')->get()
                    ->map(fn (ConversationMessage $m) => [
                        'uuid' => (string) $m->uuid,
                        'body' => (string) $m->body,
                        'provenance' => $m->provenance->value,
                        'attachments' => count((array) data_get($m->metadata, 'attachments', [])),
                    ])->values()->all(),
            ];

            DB::rollBack();

            return $thread;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** One scenario, inside whatever transaction the caller holds. */
    public function run(Scenario $scenario, Brand $brand): SimulationResult
    {
        $this->setAgentActive($scenario->agentActive);
        $this->seedGiven($scenario, $brand);

        $before = $this->counts();
        $adapter = new SimulatedChannelAdapter(Channel::Messenger);
        $conversation = null;
        $lastMessage = null;
        $refused = null;
        $stampedAt = false;
        $firstNewThreadId = (int) Conversation::query()->max('id');
        $firstNewCustomerId = (int) Customer::query()->max('id');

        foreach ($scenario->turns as $index => $turn) {
            if (isset($turn['action'])) {
                if ($conversation === null) {
                    throw new RuntimeException("Scenario [{$scenario->key}] acts before any message opened a thread.");
                }
                $conversation = $this->act($conversation->fresh(), (string) $turn['action']);

                continue;
            }

            $stampedAt = isset($turn['at']);
            $payload = ['page' => $turn['page'] ?? SimulatedChannelAdapter::DEFAULT_PAGE, 'messages' => [
                ['from' => $turn['from'], 'text' => $turn['text'] ?? null, 'mid' => $turn['mid'] ?? "sim-{$scenario->key}-{$index}",
                    'at' => $turn['at'] ?? now()->toIso8601String(), 'attachments' => $turn['attachments'] ?? []],
            ]];

            foreach ($adapter->normalise($payload) as $inbound) {
                try {
                    $lastMessage = $this->conversations->ingest($inbound);
                    $conversation = $lastMessage->conversation()->first();
                } catch (Throwable $e) {
                    $refused = $e::class;
                }
            }
        }

        $after = $this->counts();
        $conversation = $conversation?->fresh();
        $customer = $conversation?->customer;

        $observed = [
            'state' => $conversation?->state,
            'reason' => $conversation === null ? null : $this->lastTransitionReason($conversation),
            'language' => $conversation?->language,
            'stranger' => $customer === null ? null : ((bool) data_get($customer->attributes, 'stranger', false) && $customer->primary_phone_canonical === null),
            'provenance' => $lastMessage?->fresh()?->provenance->value,
            'threads' => $after['threads'] - $before['threads'],
            'customers' => $after['customers'] - $before['customers'],
            'messages' => $after['messages'] - $before['messages'],
            'refused' => $refused,
            'transitions' => $after['transitions'] - $before['transitions'],
            'audited' => $after['audited'] - $before['audited'],
            'business_scoped' => $this->businessScoped($brand, $firstNewThreadId, $firstNewCustomerId),
            'received_at' => $stampedAt ? $lastMessage?->fresh()?->received_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') : null,
        ];

        // The invariants every scenario answers to, named or not.
        $mismatches = [];
        if ($observed['audited'] !== $observed['transitions']) {
            $mismatches[] = "invariant audited: {$observed['transitions']} state change(s) but {$observed['audited']} audit row(s)";
        }
        if ($observed['business_scoped'] === false) {
            $mismatches[] = "invariant business_scoped: a thread or customer this scenario created belongs to another business than the brand's";
        }

        foreach ($scenario->expect as $key => $expected) {
            $got = $observed[$key] ?? null;
            if ($got instanceof ConversationState) {
                $got = $got->value;
            }
            if ($expected instanceof ConversationState) {
                $expected = $expected->value;
            }
            if ($got !== $expected) {
                $mismatches[] = sprintf('%s: expected %s, got %s', $key, var_export($expected, true), var_export($got, true));
            }
        }

        return new SimulationResult($scenario->key, $mismatches === [], $scenario->expect, $observed, $mismatches, $conversation?->uuid);
    }

    private function act(Conversation $conversation, string $action): Conversation
    {
        return match ($action) {
            'take_over' => $this->states->transition($conversation, ConversationState::HumanActive, null, 'simulation: a person took over'),
            'resolve' => $this->states->transition($conversation, ConversationState::HumanResolved, null, 'simulation: a person resolved it'),
            'close' => $this->states->transition($conversation, ConversationState::Closed, null, 'simulation: closed'),
            'agent_waiting' => $this->states->transition($conversation, ConversationState::AiWaiting, null, 'simulation: the agent answered and waits'),
            default => throw new RuntimeException("Unknown simulation action [{$action}]."),
        };
    }

    private function preparePages(Brand $brand): void
    {
        foreach (self::PAGES as $ref => $name) {
            PlatformAccount::query()->updateOrCreate(
                ['platform' => 'facebook_page', 'account_ref' => $ref],
                ['brand_id' => $brand->id, 'name' => $name, 'approval_status' => 'simulated', 'is_active' => true],
            );
        }
    }

    private function setAgentActive(bool $active): void
    {
        $agent = Agent::query()->where('key', ConversationService::SUPPORT_AGENT)->first();
        if ($agent === null) {
            if ($active) {
                throw new RuntimeException('The support agent row does not exist; seed AgentSeeder before a scenario that needs it active.');
            }

            return;
        }
        $agent->forceFill(['status' => $active ? 'active' : 'paused'])->save();
    }

    private function seedGiven(Scenario $scenario, Brand $brand): void
    {
        $given = $scenario->given['customer'] ?? null;
        if ($given === null) {
            return;
        }

        $customer = Customer::create([
            'business_id' => $brand->business_id,
            'name' => $given['name'] ?? null,
            'primary_phone_canonical' => $given['phone'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'attributes' => ['simulated' => true],
        ]);
        if (isset($given['identity'])) {
            CustomerIdentity::create(['customer_id' => $customer->id, 'channel' => 'messenger_psid', 'external_id' => $given['identity'], 'confidence' => 1.0]);
        }
    }

    /** @return array{threads: int, customers: int, messages: int, transitions: int, audited: int} */
    private function counts(): array
    {
        return [
            'threads' => Conversation::query()->count(),
            'customers' => Customer::query()->count(),
            'messages' => ConversationMessage::query()->count(),
            'transitions' => DomainEvent::query()->where('type', 'conversation.state_changed')->count(),
            'audited' => AuditLog::query()->where('action', 'conversation.state_changed')->count(),
        ];
    }

    /** True when every thread and customer created after the given ids belongs to the brand's business; null when none was. */
    private function businessScoped(Brand $brand, int $afterThreadId, int $afterCustomerId): ?bool
    {
        $threads = Conversation::query()->where('id', '>', $afterThreadId)->pluck('business_id')->unique()->values()->all();
        $customers = Customer::query()->where('id', '>', $afterCustomerId)->pluck('business_id')->unique()->values()->all();
        $all = array_values(array_unique([...$threads, ...$customers]));
        if ($all === []) {
            return null;
        }

        return $all === [$brand->business_id];
    }

    /**
     * Row counts of everything a replay writes, and the support agent's status: taken before and
     * after, they must be equal unless the replay was kept (docs/77 C.3.2). Sequence values do move,
     * because PostgreSQL never returns a value it handed out; that gap is the only trace.
     *
     * @return array<string, int|string|null>
     */
    public static function trace(): array
    {
        $out = [];
        foreach ([Conversation::class, ConversationMessage::class, ConversationParticipant::class, Customer::class, CustomerIdentity::class,
            ConsentEvent::class, DomainEvent::class, AuditLog::class, PlatformAccount::class, MessageDelivery::class, AgentRun::class,
            AiRun::class, ToolCall::class] as $model) {
            $table = (new $model)->getTable();
            $out[$table] = DB::table($table)->count();
        }
        $out['support_status'] = Agent::query()->where('key', ConversationService::SUPPORT_AGENT)->value('status');

        return $out;
    }

    private function lastTransitionReason(Conversation $conversation): ?string
    {
        $event = DomainEvent::query()
            ->where('type', 'conversation.state_changed')
            ->where('aggregate_id', $conversation->uuid)
            ->orderByDesc('id')
            ->first();

        return $event === null ? null : (data_get($event->payload, 'reason') ?: null);
    }
}
