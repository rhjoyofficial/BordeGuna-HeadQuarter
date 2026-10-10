<?php

namespace App\Http\Controllers\Identity;

use App\Consent\ConsentLedger;
use App\Consent\ConsentOperations;
use App\Consent\ConsentPurpose;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Controller;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Customer;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A customer's consent: what a send would get right now, the current position, the full history, and
 * the operator acts (docs/108 §7, S7).
 *
 * ⭐ The "would a send be allowed" grid is the SAME `ConsentLedger::permits` answer the Tool Gateway
 * gets, never a second rule written for a screen. The screen records events; it never edits one.
 */
final class CustomerConsentController extends Controller
{
    /** The channels the grid shows: the ones HQ has, or plans, a conversation on. */
    private const GRID_CHANNELS = [Channel::Messenger, Channel::Whatsapp, Channel::Instagram, Channel::Sms, Channel::Email];

    private const GRID_PURPOSES = [ConsentPurpose::Service, ConsentPurpose::Marketing];

    public function __construct(
        private readonly ConsentLedger $ledger,
        private readonly ConsentOperations $operations,
        private readonly BusinessScope $scope,
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

        $grid = [];
        foreach ($brandRows as $brand) {
            foreach (self::GRID_PURPOSES as $purpose) {
                $cells = [];
                foreach (self::GRID_CHANNELS as $channel) {
                    $decision = $this->ledger->permits($customer->id, $customer->business_id, $channel->value, $purpose, $brand?->id);
                    $cells[$channel->value] = [
                        'permitted' => $decision->permitted,
                        'refusal' => $decision->refusal?->value,
                        'event_id' => $decision->eventId,
                    ];
                }
                $grid[] = ['brand_id' => $brand?->id, 'brand' => $brand?->name, 'purpose' => $purpose->value, 'cells' => $cells];
            }
        }

        $position = ConsentProjection::query()->with('brand:id,code,name')
            ->where('customer_id', $customer->id)->where('business_id', $customer->business_id)
            ->orderBy('brand_id')->orderBy('channel')->orderBy('purpose')
            ->get()
            ->map(fn (ConsentProjection $p) => [
                'brand_id' => $p->brand_id,
                'brand' => $p->brand?->name,
                'channel' => $p->channel,
                'purpose' => $p->purpose?->value,
                'state' => $p->state->value,
                'effective_from' => $p->effective_from?->toIso8601String(),
                'expires_at' => $p->expires_at?->toIso8601String(),
                'last_event_id' => $p->last_event_id,
                'liftable' => $p->state->isRestriction(),
            ]);

        $history = ConsentEvent::query()->with('brand:id,code,name')
            ->where('customer_id', $customer->id)->where('business_id', $customer->business_id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (ConsentEvent $e) => [
                'id' => $e->id,
                'brand' => $e->brand?->name,
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
            'channels' => array_map(fn (Channel $c) => $c->value, self::GRID_CHANNELS),
            'purposes' => array_map(fn (ConsentPurpose $p) => $p->value, ConsentPurpose::cases()),
            'grid' => $grid,
            'position' => $position,
            'history' => $history,
            'can' => [
                'restrict' => $user->can('hq.consent.restrict'),
                'grant' => $user->can('hq.consent.grant'),
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
            'evidence_ref' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

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

    private function authorizeBusiness(Request $request, Customer $customer): void
    {
        abort_unless($this->scope->allows($request->user(), $customer->business_id), 403);
    }
}
