<?php

namespace App\Http\Controllers\FollowUps;

use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\IllegalTransition;
use App\FollowUps\OpportunityCloseReason;
use App\FollowUps\OpportunityKind;
use App\FollowUps\OpportunityProposal;
use App\FollowUps\OpportunityState;
use App\FollowUps\Outcomes\FollowUpOutcomeLink;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Controller;
use App\Identity\Quarantine\CrossBusinessSuppression;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The owner/operator follow-up screen (docs/113 sections 3 to 5): read, propose, approve, decline,
 * complete, cancel; open, decline and close an opportunity. Every write is a state transition of an
 * HQ-owned row through `FollowUpProposalService`; nothing here composes or sends anything.
 *
 * ⛔ No route takes a message, a tool key or a provider. The browser names a row and a decision.
 */
final class FollowUpController extends Controller
{
    private const FOLLOW_UP_FILTERS = ['open', 'due', 'proposed', 'scheduled', 'refused', 'declined', 'done', 'cancelled', 'all'];

    private const OPPORTUNITY_FILTERS = ['active', 'proposed', 'open', 'declined', 'closed', 'all'];

    public function __construct(
        private readonly FollowUpProposalService $service,
        private readonly BusinessScope $scope,
        private readonly FollowUpOutcomeLink $ledgerLink,
    ) {}

    /**
     * The ledger's credits for the listed done follow-ups, asked one business at a time (each read is
     * bound to that business and to the viewer's scope inside the link).
     *
     * @param  Collection<int, FollowUp>  $followUps
     * @return array<int, list<array<string, mixed>>>
     */
    private function credits(Collection $followUps, User $user): array
    {
        $credits = [];
        foreach ($followUps->where('state', FollowUpState::Done)->groupBy('business_id') as $businessId => $rows) {
            $credits += $this->ledgerLink->creditsFor((int) $businessId, $rows->pluck('id')->all(), $user);
        }

        return $credits;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $state = (string) $request->query('state', 'open');
        $state = in_array($state, self::FOLLOW_UP_FILTERS, true) ? $state : 'open';
        $opportunityState = (string) $request->query('opportunity_state', 'active');
        $opportunityState = in_array($opportunityState, self::OPPORTUNITY_FILTERS, true) ? $opportunityState : 'active';

        $followUps = FollowUp::query()->visibleTo($user)
            ->with(['customer:id,name,primary_phone_canonical', 'brand:id,code,name', 'opportunity:id,uuid,subject', 'proposedBy:id,email', 'decidedBy:id,email', 'business:id,name'])
            ->when($state === 'open', fn ($q) => $q->open())
            ->when($state === 'due', fn ($q) => $q->where('state', FollowUpState::Scheduled->value)->where('due_at', '<=', now()))
            ->when(! in_array($state, ['open', 'due', 'all'], true), fn ($q) => $q->where('state', $state))
            ->orderByRaw("CASE WHEN state = 'proposed' THEN 0 WHEN state = 'scheduled' THEN 1 ELSE 2 END")
            ->orderBy('due_at')->orderByDesc('id')
            ->limit(200)->get();
        $credits = $this->credits($followUps, $user);
        // docs/122 Q3, docs/126: a phone the Customer 360 page withholds (it may have come from another business) is
        // withheld here too; the rule is the one SQL condition every customer screen applies.
        $withheldPhones = CrossBusinessSuppression::withheldPhoneCustomerIds($followUps->pluck('customer_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        $followUps = $followUps
            ->map(fn (FollowUp $f) => [
                'uuid' => $f->uuid,
                'state' => $f->state->value,
                'kind' => $f->kind->value,
                'origin' => $f->origin->value,
                'channel' => $f->channel,
                'due_at' => $f->due_at->toIso8601String(),
                'due' => $f->isDue(),
                'intent' => $f->intent,
                'customer' => ['id' => $f->customer_id, 'name' => $f->customer?->name,
                    'phone' => in_array((int) $f->customer_id, $withheldPhones, true) ? null : $f->customer?->primary_phone_canonical],
                'business' => $f->business?->name,
                'brand' => $f->brand?->name,
                'opportunity' => $f->opportunity ? ['uuid' => $f->opportunity->uuid, 'subject' => $f->opportunity->subject] : null,
                'proposer' => $f->proposer_type === Proposer::PERSON ? $f->proposedBy?->email : 'agent run'.($f->proposal_ref ? " ({$f->proposal_ref})" : ''),
                'proposer_type' => $f->proposer_type,
                'consent' => ['permitted' => $f->consent_permitted, 'refusal' => $f->consent_refusal?->value, 'event_id' => $f->consent_event_id, 'checked_at' => $f->consent_checked_at?->toIso8601String()],
                'decided_by' => $f->decidedBy?->email,
                'decision_reason' => $f->decision_reason,
                'close_note' => $f->close_note,
                'workflow_instance_key' => $f->workflow_instance_key,
                // docs/120 item 11: a decider deciding on their own proposal is allowed by role, and shown.
                'self_decided' => $f->proposer_type === Proposer::PERSON && $f->decided_by_user_id !== null && $f->decided_by_user_id === $f->proposed_by_user_id,
                // docs/123: what the outcome ledger credits to this follow-up (current view, never an amount).
                'ledger_credits' => $credits[$f->id] ?? [],
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        $opportunities = Opportunity::query()->visibleTo($user)
            ->with(['customer:id,name,primary_phone_canonical', 'brand:id,code,name', 'proposedBy:id,email', 'decidedBy:id,email', 'business:id,name'])
            ->withCount(['followUps as open_follow_ups_count' => fn ($q) => $q->open()])
            ->when($opportunityState === 'active', fn ($q) => $q->whereIn('state', [OpportunityState::Proposed->value, OpportunityState::Open->value]))
            ->when(! in_array($opportunityState, ['active', 'all'], true), fn ($q) => $q->where('state', $opportunityState))
            ->orderByRaw("CASE WHEN state = 'proposed' THEN 0 WHEN state = 'open' THEN 1 ELSE 2 END")
            ->orderBy('expected_at')->orderByDesc('id')
            ->limit(200)->get();
        $withheldOpportunityPhones = CrossBusinessSuppression::withheldPhoneCustomerIds($opportunities->pluck('customer_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        $opportunities = $opportunities
            ->map(fn (Opportunity $o) => [
                'uuid' => $o->uuid,
                'state' => $o->state->value,
                'kind' => $o->kind->value,
                'subject' => $o->subject,
                'expected_at' => $o->expected_at?->toDateString(),
                'customer' => ['id' => $o->customer_id, 'name' => $o->customer?->name,
                    'phone' => in_array((int) $o->customer_id, $withheldOpportunityPhones, true) ? null : $o->customer?->primary_phone_canonical],
                'business' => $o->business?->name,
                'brand' => $o->brand?->name,
                'proposer' => $o->proposer_type === Proposer::PERSON ? $o->proposedBy?->email : 'agent run'.($o->proposal_ref ? " ({$o->proposal_ref})" : ''),
                'proposer_type' => $o->proposer_type,
                'decided_by' => $o->decidedBy?->email,
                'decision_reason' => $o->decision_reason,
                'close_reason' => $o->close_reason?->value,
                'close_note' => $o->close_note,
                'open_follow_ups' => (int) $o->open_follow_ups_count,
                'self_decided' => $o->proposer_type === Proposer::PERSON && $o->decided_by_user_id !== null && $o->decided_by_user_id === $o->proposed_by_user_id,
                // docs/113 F4, docs/123: `converted` stays the person's word; the ledger only says whether it is supported.
                'conversion_supported' => $o->close_reason === OpportunityCloseReason::Converted ? $this->ledgerLink->supportsConversion($o, $user) : null,
                'created_at' => $o->created_at?->toIso8601String(),
            ]);

        $openForLinking = Opportunity::query()->visibleTo($user)
            ->whereIn('state', [OpportunityState::Proposed->value, OpportunityState::Open->value])
            ->with('customer:id,name')->orderByDesc('id')->limit(200)->get()
            ->map(fn (Opportunity $o) => ['id' => $o->id, 'customer_id' => $o->customer_id, 'label' => ($o->customer?->name ?? "#{$o->customer_id}").': '.$o->subject]);

        $brands = Brand::query()->orderBy('name')->get(['id', 'business_id', 'code', 'name'])
            ->filter(fn (Brand $b) => $this->scope->allows($user, $b->business_id))
            ->map(fn (Brand $b) => ['id' => $b->id, 'business_id' => $b->business_id, 'name' => $b->name])->values();

        $prefill = (int) $request->query('customer', 0);
        $prefillCustomer = $prefill > 0 ? Customer::query()->find($prefill, ['id', 'name', 'business_id']) : null;
        if ($prefillCustomer !== null && ! $this->scope->allows($user, $prefillCustomer->business_id)) {
            $prefillCustomer = null;
        }

        return Inertia::render('follow-ups/index', [
            'followUps' => $followUps,
            'opportunities' => $opportunities,
            'filters' => ['state' => $state, 'opportunity_state' => $opportunityState],
            'counts' => [
                'proposed' => FollowUp::query()->visibleTo($user)->where('state', FollowUpState::Proposed->value)->count(),
                'due' => FollowUp::query()->visibleTo($user)->where('state', FollowUpState::Scheduled->value)->where('due_at', '<=', now())->count(),
                'scheduled' => FollowUp::query()->visibleTo($user)->where('state', FollowUpState::Scheduled->value)->count(),
                'opportunities_proposed' => Opportunity::query()->visibleTo($user)->where('state', OpportunityState::Proposed->value)->count(),
            ],
            'options' => [
                'kinds' => array_map(fn (FollowUpKind $k) => $k->value, FollowUpKind::cases()),
                'origins' => array_map(fn (FollowUpOrigin $o) => $o->value, FollowUpOrigin::cases()),
                'channels' => array_values(array_map(fn (Channel $c) => $c->value, array_filter(Channel::cases(), fn (Channel $c) => $c !== Channel::Internal))),
                'opportunity_kinds' => array_map(fn (OpportunityKind $k) => $k->value, OpportunityKind::cases()),
                'close_reasons' => array_map(fn (OpportunityCloseReason $r) => $r->value, OpportunityCloseReason::cases()),
                'brands' => $brands,
                'open_opportunities' => $openForLinking,
            ],
            'prefill' => $prefillCustomer ? ['customer_id' => $prefillCustomer->id, 'customer_name' => $prefillCustomer->name, 'business_id' => $prefillCustomer->business_id] : null,
            'can' => [
                'propose' => $user->can(FollowUpProposalService::PERMISSION_PROPOSE),
                'decide' => $user->can(FollowUpProposalService::PERMISSION_DECIDE),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'kind' => ['required', Rule::enum(FollowUpKind::class)],
            'origin' => ['required', Rule::enum(FollowUpOrigin::class)],
            'channel' => ['required', Rule::in(array_values(array_map(fn (Channel $c) => $c->value, array_filter(Channel::cases(), fn (Channel $c) => $c !== Channel::Internal))))],
            'due_at' => ['required', 'date'],
            'intent' => ['required', 'string', 'max:500'],
            'brand_id' => ['nullable', 'integer'],
            'opportunity_id' => ['nullable', 'integer'],
        ]);

        $customer = $this->customer($request, (int) $data['customer_id']);
        $opportunity = isset($data['opportunity_id']) ? Opportunity::query()->find((int) $data['opportunity_id']) : null;
        if (isset($data['opportunity_id']) && $opportunity === null) {
            return back()->withErrors(['opportunity_id' => 'Unknown opportunity.'])->withInput();
        }

        return $this->attempt(fn () => $this->service->proposeFollowUp(Proposer::person($request->user()), new FollowUpProposal(
            customer: $customer,
            kind: FollowUpKind::from($data['kind']),
            channel: Channel::from($data['channel']),
            dueAt: new \DateTimeImmutable($data['due_at']),
            intent: $data['intent'],
            origin: FollowUpOrigin::from($data['origin']),
            brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            opportunity: $opportunity,
        )), fn (FollowUp $f) => $f->state === FollowUpState::Refused ? 'follow-up-refused:'.$f->consent_refusal?->value : 'follow-up-proposed', 'intent');
    }

    public function storeOpportunity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'kind' => ['required', Rule::enum(OpportunityKind::class)],
            'subject' => ['required', 'string', 'max:500'],
            'expected_at' => ['nullable', 'date'],
            'brand_id' => ['nullable', 'integer'],
        ]);

        $customer = $this->customer($request, (int) $data['customer_id']);

        return $this->attempt(fn () => $this->service->proposeOpportunity(Proposer::person($request->user()), new OpportunityProposal(
            customer: $customer,
            kind: OpportunityKind::from($data['kind']),
            subject: $data['subject'],
            expectedAt: isset($data['expected_at']) ? new \DateTimeImmutable($data['expected_at']) : null,
            brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
        )), fn () => 'opportunity-proposed', 'subject');
    }

    public function approve(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->inScope($request, $followUp);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        return $this->attempt(fn () => $this->service->approveFollowUp($followUp, $request->user(), $reason),
            fn (FollowUp $f) => $f->state === FollowUpState::Refused ? 'follow-up-refused:'.$f->consent_refusal?->value : 'follow-up-scheduled', 'reason');
    }

    public function decline(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->inScope($request, $followUp);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->service->declineFollowUp($followUp, $request->user(), $reason), fn () => 'follow-up-declined', 'reason');
    }

    public function complete(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->inScope($request, $followUp);
        $note = $request->validate(['note' => ['required', 'string', 'max:1000']])['note'];

        return $this->attempt(fn () => $this->service->completeFollowUp($followUp, $request->user(), $note), fn () => 'follow-up-done', 'note');
    }

    public function cancel(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->inScope($request, $followUp);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->service->cancelFollowUp($followUp, $request->user(), $reason), fn () => 'follow-up-cancelled', 'reason');
    }

    public function openOpportunity(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->inScope($request, $opportunity);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        return $this->attempt(fn () => $this->service->openOpportunity($opportunity, $request->user(), $reason), fn () => 'opportunity-opened', 'reason');
    }

    public function declineOpportunity(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->inScope($request, $opportunity);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->service->declineOpportunity($opportunity, $request->user(), $reason), fn () => 'opportunity-declined', 'reason');
    }

    public function closeOpportunity(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->inScope($request, $opportunity);
        $data = $request->validate(['close_reason' => ['required', Rule::enum(OpportunityCloseReason::class)], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->closeOpportunity($opportunity, $request->user(), OpportunityCloseReason::from($data['close_reason']), $data['note'] ?? null),
            fn () => 'opportunity-closed', 'close_reason');
    }

    private function customer(Request $request, int $id): Customer
    {
        $customer = Customer::query()->find($id);
        abort_if($customer === null || ! $this->scope->allows($request->user(), $customer->business_id), 404);

        return $customer;
    }

    /** A row of another business is not there (docs/109 tightened the same for customers). */
    private function inScope(Request $request, FollowUp|Opportunity $row): void
    {
        abort_unless($this->scope->allows($request->user(), $row->business_id), 404);
    }

    /** The service's refusals become form errors, never a 500 and never a silent success. */
    private function attempt(callable $act, callable $status, string $errorField): RedirectResponse
    {
        try {
            $result = $act();
        } catch (InvalidArgumentException|IllegalTransition $e) {
            return back()->withErrors([$errorField => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('status', $status($result));
    }
}
