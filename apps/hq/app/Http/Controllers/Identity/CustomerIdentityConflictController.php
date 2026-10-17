<?php

namespace App\Http\Controllers\Identity;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Identity\Conflicts\ReleaseEvidence;
use App\Models\Business;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerIdentityConflictResolution as Resolution;
use App\Support\Authorization\BusinessScope;
use App\Tools\Identity\ResolveIdentityConflictTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * docs/140 (ruling docs/131 item 3, docs/138 P3-W1): the open identity conflicts of the businesses a person may read,
 * and the human resolution of one of them.
 *
 * Reading is behind `hq.customers.quarantine.review` (the same people who review cross-business flags). Resolving is a
 * Tool Gateway request for the permanently human-only tool `customers.identity_conflicts.resolve` as the signed-in
 * person, behind `hq.customers.identity_conflicts.resolve`; this controller never writes a conflict, an identity or a
 * resolution itself.
 *
 * ⚠ Business scope: a conflict belongs to ONE business. A person sees only the conflicts of businesses they may read,
 * and a conflict of any other business answers 404, like every record you may not see. Ids, channels and a masked
 * key only; no customer's name or full phone reaches the screen.
 */
final class CustomerIdentityConflictController extends Controller
{
    public function __construct(
        private readonly BusinessScope $businessScope,
        private readonly ReleaseEvidence $release,
        private readonly ToolGateway $gateway,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $allowed = $this->businessScope->all($user);
        $businesses = Business::query()->pluck('name', 'id');

        $open = CustomerIdentityConflict::query()
            ->whereNull('resolved_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('business_id', $allowed))
            ->with('identity')
            ->orderBy('business_id')->orderBy('id')
            ->limit(500)
            ->get();

        $resolved = Resolution::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('business_id', $allowed))
            ->with('resolver:id,email')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return Inertia::render('customers/identity-conflicts', [
            'conflicts' => $open->map(fn (CustomerIdentityConflict $c) => $this->row($c, $businesses->all()))->values()->all(),
            'resolved' => $resolved->map(fn (Resolution $r) => [
                'id' => $r->id,
                'conflict_id' => $r->conflict_id,
                'business' => $businesses[$r->business_id] ?? null,
                'resolution' => $r->resolution,
                'owner_before' => $r->owner_before_customer_id,
                'owner_after' => $r->owner_after_customer_id,
                'released' => $r->released_from_quarantine,
                'reason' => $r->reason,
                'by' => $r->resolver?->email,
                'at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
            'can_resolve' => $user->can(ResolveIdentityConflictTool::PERMISSION),
        ]);
    }

    /**
     * Resolve one conflict: a gateway request for `customers.identity_conflicts.resolve` as the signed-in person. The tool
     * refuses a conflict of another business, a claimant of another business, a resolved conflict decided another way
     * and a held identity the rows do not support releasing.
     */
    public function resolve(Request $request, int $conflict): RedirectResponse
    {
        $row = CustomerIdentityConflict::query()->find($conflict);
        if ($row === null || ! $this->businessScope->allows($request->user(), (int) $row->business_id)) {
            abort(404);
        }

        $data = $request->validate([
            'resolution' => ['required', 'string', Rule::in(Resolution::CHOICES)],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $outcome = $this->gateway->execute(ToolRequest::forOperator($request->user(), ResolveIdentityConflictTool::KEY, [
            'business_slug' => (string) Business::query()->whereKey($row->business_id)->value('slug'),
            'conflict_id' => (int) $row->id,
            'resolution' => $data['resolution'],
            'evidence' => $data['evidence'],
            'reason' => $data['reason'],
        ], (int) $row->business_id));

        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            throw ValidationException::withMessages(['resolution' => (string) ($outcome->error ?? $outcome->policyReason ?? 'The resolution was refused.')]);
        }

        return back()->with('status', 'identity-conflict-resolved');
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function row(CustomerIdentityConflict $c, array $names): array
    {
        $identity = $c->identity;
        $held = $identity?->quarantined_at !== null;
        $blockers = $identity !== null && $held ? $this->release->blockers($identity, (int) $c->business_id) : [];
        $movable = $c->kind === CustomerIdentityConflict::KIND_LINK_REFUSED && $c->claimant_customer_id !== null;

        return [
            'id' => $c->id,
            'business' => $names[$c->business_id] ?? null,
            'kind' => $c->kind,
            'reason' => $c->reason,
            'source' => $c->source,
            'identity_id' => $c->identity_id,
            'channel' => $identity?->channel,
            'key' => $identity === null ? null : self::mask((string) $identity->external_id),
            'store_id' => $identity?->source_store_id,
            'held' => $held,
            'held_reason' => $identity?->quarantine_reason,
            'owner_customer_id' => $identity?->customer_id,
            'holder_customer_id' => $c->holder_customer_id,
            'claimant_customer_id' => $c->claimant_customer_id,
            'occurrences' => (int) $c->occurrences,
            'first_seen_at' => $c->first_seen_at?->toIso8601String(),
            'last_seen_at' => $c->last_seen_at?->toIso8601String(),
            'release_blockers' => array_keys($blockers),
            'choices' => array_values(array_filter([
                $held && $blockers !== [] ? null : Resolution::KEEP_OWNER,
                $movable && ($blockers === []) ? Resolution::MOVE_TO_CLAIMANT : null,
                Resolution::NOT_RESOLVABLE,
            ])),
        ];
    }

    /** Enough of a key to recognise it against the evidence in hand, never the whole value. */
    private static function mask(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', min($length - 4, 8)).mb_substr($value, -4);
    }
}
