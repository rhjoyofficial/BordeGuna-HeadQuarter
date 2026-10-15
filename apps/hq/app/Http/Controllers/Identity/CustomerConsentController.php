<?php

namespace App\Http\Controllers\Identity;

use App\Consent\ConsentLedger;
use App\Consent\ConsentOperations;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Gateway\Descriptor\Channel;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Support\Authorization\BusinessScope;
use App\Tools\Consent\LiftRestrictionTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A customer's consent: what a send would get right now, the current position, the full history, and
 * the operator acts (docs/108 §7, S7; docs/114 §2 and §3, the follow-ups).
 *
 * ⭐ The "would a send be allowed" grid is the SAME `ConsentLedger::permits` answer the Tool Gateway
 * gets, never a second rule written for a screen. The screen records events; it never edits one.
 *
 * Every act may name ONE IDENTITY of the customer or the whole customer (docs/114 §2). Lifting a
 * DO-NOT-CONTACT is not recorded here at all: the screen hands it to the Tool Gateway as the signed-in
 * person (`consent.lift_restriction`), the gateway parks it, and a second person decides it in the
 * Approval Centre (docs/114 §3). Until then the pending lift is listed and the restriction stands.
 */
final class CustomerConsentController extends Controller
{
    /** The channels the grid shows: the ones HQ has, or plans, a conversation on. */
    private const GRID_CHANNELS = [Channel::Messenger, Channel::Whatsapp, Channel::Instagram, Channel::Sms, Channel::Email];

    private const GRID_PURPOSES = [ConsentPurpose::Service, ConsentPurpose::Marketing];

    /** The conversation channel an identity row's channel reaches (the inverse of `Channel::identityChannel()`). */
    private const IDENTITY_CHANNELS = [
        'messenger_psid' => Channel::Messenger,
        'instagram_igsid' => Channel::Instagram,
        'whatsapp' => Channel::Whatsapp,
        'phone' => Channel::Sms,
        'email' => Channel::Email,
        'facebook_user' => Channel::Comment,
    ];

    public function __construct(
        private readonly ConsentLedger $ledger,
        private readonly ConsentOperations $operations,
        private readonly BusinessScope $scope,
        private readonly ToolGateway $gateway,
    ) {}

    public function show(Request $request, Customer $customer): Response|RedirectResponse
    {
        $this->authorizeBusiness($request, $customer);
        $canonical = $customer->canonical();
        if ($canonical->id !== $customer->id) {
            return redirect()->route('customers.consent', $canonical);
        }

        $customer->load('business:id,name');
        $brands = $customer->business?->brands()->orderBy('name')->get(['id', 'code', 'name']) ?? collect();
        $brandRows = $brands->isEmpty() ? collect([null]) : $brands;
        // Live identities of this customer's own business only (docs/122): a quarantined row is never offered.
        $identities = $customer->identities()->live()->where('business_id', $customer->business_id)->orderBy('id')->get(['id', 'channel', 'external_id']);
        $identityLabels = $identities->mapWithKeys(fn (CustomerIdentity $i) => [$i->id => self::identityLabel($i)]);

        // Customer-wide: what a send to this person gets when no identity is resolved (fail closed on
        // identity-scoped grants) or when none is scoped. Same call as the gateway.
        $grid = [];
        foreach ($brandRows as $brand) {
            foreach (self::GRID_PURPOSES as $purpose) {
                $cells = [];
                foreach (self::GRID_CHANNELS as $channel) {
                    $cells[$channel->value] = $this->cell($customer, $channel, $purpose, $brand?->id, null);
                }
                $grid[] = ['brand_id' => $brand?->id, 'brand' => $brand?->name, 'purpose' => $purpose->value, 'cells' => $cells];
            }
        }

        // Per identity (docs/114 §2): the one channel that identity lives on, per brand and purpose.
        $identityGrid = [];
        foreach ($identities as $identity) {
            $channel = self::IDENTITY_CHANNELS[$identity->channel] ?? null;
            if ($channel === null) {
                continue;
            }
            foreach ($brandRows as $brand) {
                $cells = [];
                foreach (self::GRID_PURPOSES as $purpose) {
                    $cells[$purpose->value] = $this->cell($customer, $channel, $purpose, $brand?->id, $identity->id);
                }
                $identityGrid[] = [
                    'identity_id' => $identity->id, 'identity' => $identityLabels[$identity->id], 'channel' => $channel->value,
                    'brand_id' => $brand?->id, 'brand' => $brand?->name, 'cells' => $cells,
                ];
            }
        }

        $position = ConsentProjection::query()->with('brand:id,code,name')
            ->where('customer_id', $customer->id)->where('business_id', $customer->business_id)
            // Broadest scope first: the customer-wide row before a brand's, an identity's or a channel's.
            ->orderByRaw('brand_id NULLS FIRST, identity_id NULLS FIRST, channel NULLS FIRST, purpose NULLS FIRST, id')
            ->get()
            ->map(fn (ConsentProjection $p) => [
                'brand_id' => $p->brand_id,
                'brand' => $p->brand?->name,
                'identity_id' => $p->identity_id,
                'identity' => $p->identity_id === null ? null : ($identityLabels[$p->identity_id] ?? "#{$p->identity_id}"),
                'channel' => $p->channel,
                'purpose' => $p->purpose?->value,
                'state' => $p->state->value,
                'effective_from' => $p->effective_from?->toIso8601String(),
                'expires_at' => $p->expires_at?->toIso8601String(),
                'last_event_id' => $p->last_event_id,
                'liftable' => $p->state->isRestriction(),
                // docs/114 §3: a do-not-contact is lifted by two people; the screen says so before anybody asks.
                'two_person' => $p->state === ConsentState::DoNotContact,
            ]);

        $history = ConsentEvent::query()->with('brand:id,code,name')
            ->where('customer_id', $customer->id)->where('business_id', $customer->business_id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (ConsentEvent $e) => [
                'id' => $e->id,
                'brand' => $e->brand?->name,
                'identity' => $e->identity_id === null ? null : ($identityLabels[$e->identity_id] ?? "#{$e->identity_id}"),
                'channel' => $e->channel,
                'purpose' => $e->purpose?->value,
                'state' => $e->state->value,
                'basis' => $e->basis->value,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'recorded_at' => $e->recorded_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'evidence_ref' => $e->evidence_ref,
                'actor_type' => $e->actor_type,
                'actor_label' => $e->actor_label,
                'reason' => $e->reason,
                'two_person' => ($e->evidence['act'] ?? null) === 'lift_do_not_contact',
            ]);

        // Lifts of a do-not-contact waiting for a second person (docs/114 §3). Read from the approvals
        // table, which is where they live; the restriction stands until one is approved.
        $pendingLifts = Approval::query()->pending()->with('requestedBy:id,name,email')
            ->where('tool_key', LiftRestrictionTool::KEY)
            ->where('payload->customer_id', $customer->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Approval $a) => [
                'id' => $a->id,
                'uuid' => $a->uuid,
                'brand' => isset($a->payload['brand_id']) ? ($brands->firstWhere('id', (int) $a->payload['brand_id'])?->name) : null,
                'identity' => isset($a->payload['identity_id']) ? ($identityLabels[(int) $a->payload['identity_id']] ?? '#'.$a->payload['identity_id']) : null,
                'channel' => $a->payload['channel'] ?? null,
                'requested_by' => $a->requestedBy?->email,
                'requested_by_id' => $a->requested_by_user_id,
                'requested_at' => $a->created_at?->toIso8601String(),
                'reason' => $a->reason,
            ]);

        $user = $request->user();

        return Inertia::render('customers/consent', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->primary_phone_canonical,
                'business' => $customer->business?->name,
            ],
            'brands' => $brands->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values(),
            'identities' => $identities->map(fn (CustomerIdentity $i) => ['id' => $i->id, 'channel' => $i->channel, 'label' => $identityLabels[$i->id]])->values(),
            'channels' => array_map(fn (Channel $c) => $c->value, self::GRID_CHANNELS),
            'purposes' => array_map(fn (ConsentPurpose $p) => $p->value, ConsentPurpose::cases()),
            'grid' => $grid,
            'identity_grid' => $identityGrid,
            'position' => $position,
            'history' => $history,
            'pending_lifts' => $pendingLifts,
            'can' => [
                'restrict' => $user->can('hq.consent.restrict'),
                'grant' => $user->can('hq.consent.grant'),
                'lift_restriction' => $user->can(ConsentOperations::PERMISSION_LIFT_RESTRICTION),
            ],
        ]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeBusiness($request, $customer);

        $channels = array_values(array_filter(array_map(fn (Channel $c) => $c->value, Channel::cases()), fn ($c) => $c !== Channel::Internal->value));
        $data = $request->validate([
            'act' => ['required', Rule::in([...ConsentOperations::RESTRICTING, ...ConsentOperations::WIDENING])],
            'purpose' => ['nullable', Rule::in(array_map(fn (ConsentPurpose $p) => $p->value, ConsentPurpose::cases()))],
            'channel' => ['nullable', Rule::in($channels)],
            'brand_id' => ['nullable', 'integer'],
            'identity_id' => ['nullable', 'integer'],
            'evidence_ref' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // docs/114 §3: lifting a DO-NOT-CONTACT is a gateway tool that parks for a second person, under its
        // own permission. Decided by what STANDS at the scope, read from the ledger, never by what was typed.
        if ($data['act'] === ConsentOperations::ACT_LIFT && $this->standingDoNotContact($customer, $data)) {
            abort_unless($request->user()->can(ConsentOperations::PERMISSION_LIFT_RESTRICTION), 403);

            return $this->requestLift($request, $customer, $data);
        }

        // Restricting and widening are separate permissions (docs/108 §5): the person who may stop
        // contact is not automatically somebody who may start it.
        abort_unless($request->user()->can(ConsentOperations::permissionFor($data['act'])), 403);

        try {
            $event = $this->operations->record($request->user(), $customer, $data['act'], $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['act' => $e->getMessage()])->withInput();
        }

        return back()->with('status', "consent-recorded:{$event->id}");
    }

    /** Asks the gateway, as the signed-in person, to park the lift for a second approver. Nothing is lifted here. */
    private function requestLift(Request $request, Customer $customer, array $data): RedirectResponse
    {
        $args = array_filter([
            'business_slug' => $customer->business()->value('slug'),
            'customer_id' => $customer->id,
            'identity_id' => isset($data['identity_id']) ? (int) $data['identity_id'] : null,
            'brand_id' => isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            'channel' => $data['channel'] ?? null,
            'evidence_ref' => $data['evidence_ref'],
            'reason' => $data['reason'],
        ], fn ($v) => $v !== null && $v !== '');

        $outcome = $this->gateway->execute(ToolRequest::forOperator($request->user(), LiftRestrictionTool::KEY, $args, $customer->business_id));

        if ($outcome->status === OutcomeStatus::AwaitingApproval) {
            return back()->with('status', 'lift-pending:'.($outcome->output['approval_id'] ?? ''));
        }

        return back()->withErrors(['act' => (string) ($outcome->error ?? $outcome->policyReason ?? 'The lift was refused.')])->withInput();
    }

    private function standingDoNotContact(Customer $customer, array $data): bool
    {
        $standing = $this->ledger->latestAtScope(
            $customer->id, $customer->business_id,
            isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            $data['channel'] ?? null,
            $data['purpose'] ?? null,
            isset($data['identity_id']) ? (int) $data['identity_id'] : null,
        );

        return $standing?->state === ConsentState::DoNotContact;
    }

    /** @return array{permitted: bool, refusal: ?string, event_id: ?int} */
    private function cell(Customer $customer, Channel $channel, ConsentPurpose $purpose, ?int $brandId, ?int $identityId): array
    {
        $decision = $this->ledger->permits($customer->id, $customer->business_id, $channel->value, $purpose, $brandId, $identityId);

        return ['permitted' => $decision->permitted, 'refusal' => $decision->refusal?->value, 'event_id' => $decision->eventId];
    }

    /** Enough of the external id to tell two identities of one person apart, no more. */
    private static function identityLabel(CustomerIdentity $identity): string
    {
        $id = (string) $identity->external_id;
        $shown = mb_strlen($id) <= 4 ? $id : '...'.mb_substr($id, -4);

        return "{$identity->channel} {$shown} (#{$identity->id})";
    }

    private function authorizeBusiness(Request $request, Customer $customer): void
    {
        abort_unless($this->scope->allows($request->user(), $customer->business_id), 403);
    }
}
