<?php

namespace App\Http\Controllers\Identity;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Identity\Quarantine\QuarantineDecision;
use App\Identity\Quarantine\QuarantineLedger;
use App\Identity\Repair\HeldMemoryAssigner;
use App\Identity\Repair\RepairPlan;
use App\Identity\Repair\RepairPlanner;
use App\Models\Business;
use App\Models\CrossBusinessQuarantine;
use App\Models\CustomerIdentityRepair;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use App\Tools\Identity\QuarantineRepairTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * docs/117 §5 (ruling docs/110 §5 (m)): the human review of quarantine flags on cross-business
 * relationships. A review is an audited, append-only decision row; it lifts no suppression and repairs
 * nothing by itself.
 *
 * docs/126 (ruling docs/120 A3, item 36): the same screen shows the dry run of the repair (the batches the
 * planner proposes, with the review state of every row) and lets a person holding `hq.customers.quarantine.repair`
 * apply ONE batch whose every row they reviewed. The apply is a Tool Gateway request for the human-only tool
 * `customers.quarantine.repair` as that person; this controller never reaches the applier. It also lets that
 * person assign a business to a memory the repair withheld (A3 point 8).
 *
 * ⚠ Business scope: a flag joins two businesses, so a person sees (and may review) it only when BOTH are
 * theirs. Any other flag answers 404, like every record you may not see. A batch or a held memory is shown only
 * when every business it touches is readable.
 */
final class CustomerQuarantineController extends Controller
{
    public function __construct(
        private readonly QuarantineLedger $ledger,
        private readonly BusinessScope $businessScope,
        private readonly RepairPlanner $planner,
        private readonly HeldMemoryAssigner $memories,
        private readonly ToolGateway $gateway,
    ) {}

    private function readable(User $user): Builder
    {
        $query = CrossBusinessQuarantine::query();
        $allowed = $this->businessScope->all($user);
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn('customer_business_id', $allowed)
            ->where(fn ($q) => $q->whereNull('row_business_id')->orWhereIn('row_business_id', $allowed));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $flags = $this->readable($user)
            ->with(['latestReview.reviewer:id,email'])
            ->withCount('reviews')
            ->orderBy('id')
            ->limit(500)
            ->get();
        $businesses = Business::query()->pluck('name', 'id');
        $allowed = $this->businessScope->all($user);
        $plan = $this->planner->plan();

        return Inertia::render('customers/quarantine', [
            'flags' => $flags->map(fn (CrossBusinessQuarantine $f) => [
                'id' => $f->id,
                'kind' => $f->kind,
                'subject' => "{$f->subject_table}#{$f->subject_id}",
                'customer_id' => $f->customer_id,
                'customer_business' => $businesses[$f->customer_business_id] ?? null,
                'row_business' => $f->row_business_id === null ? null : ($businesses[$f->row_business_id] ?? null),
                'merge_id' => $f->merge_id,
                'reason' => $f->reason,
                'detected_at' => $f->detected_at?->toIso8601String(),
                'reviews' => (int) $f->reviews_count,
                'review' => $f->latestReview === null ? null : [
                    'decision' => $f->latestReview->decision->value,
                    'note' => $f->latestReview->note,
                    'by' => $f->latestReview->reviewer?->email,
                    'at' => $f->latestReview->reviewed_at?->toIso8601String(),
                ],
            ])->values()->all(),
            'decisions' => array_map(fn (QuarantineDecision $d) => $d->value, QuarantineDecision::cases()),
            'can_repair' => $user->can(QuarantineRepairTool::PERMISSION),
            'batches' => $this->batches($plan, $allowed, $businesses->all()),
            'ambiguous' => collect($plan->ambiguous)
                ->filter(fn (array $a) => $this->within($allowed, [(int) $a['customer_business_id'], $a['row_business_id'] === null ? null : (int) $a['row_business_id']]))
                ->map(fn (array $a) => ['subject' => "{$a['subject_table']}#{$a['subject_id']}", 'customer_id' => (int) $a['customer_id'], 'reason' => $a['reason'], 'why' => $a['why']])
                ->values()->all(),
            'held_memories' => array_map(fn (array $m) => $m + [
                'customer_business' => $businesses[$m['customer_business_id']] ?? null,
                'choice_names' => array_map(fn (int $id) => ['id' => $id, 'name' => $businesses[$id] ?? (string) $id], $m['choices']),
            ], $this->memories->held($allowed, $plan)),
            'repairs' => CustomerIdentityRepair::query()
                ->when($allowed !== null, fn ($q) => $q->whereIn('business_id', $allowed))
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn (CustomerIdentityRepair $r) => [
                    'id' => $r->id, 'key' => $r->key, 'business' => $businesses[$r->business_id] ?? null, 'status' => $r->status,
                    'started_at' => $r->started_at?->toIso8601String(), 'verified_at' => $r->verified_at?->toIso8601String(),
                    'splits' => count($r->plan['batch']['splits'] ?? []),
                ])->values()->all(),
        ]);
    }

    public function review(Request $request, CrossBusinessQuarantine $quarantine): RedirectResponse
    {
        if (! $this->readable($request->user())->whereKey($quarantine->getKey())->exists()) {
            abort(404);
        }

        $data = $request->validate([
            'decision' => ['required', Rule::enum(QuarantineDecision::class)],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $this->ledger->review($quarantine, QuarantineDecision::from($data['decision']), $data['note'], $request->user());

        return back()->with('status', 'quarantine-reviewed');
    }

    /**
     * Apply one reviewed batch: a gateway request for `customers.quarantine.repair` as the signed-in person. The tool
     * refuses a changed batch, an unreviewed or disputed row, and a business the person may not read.
     */
    public function repair(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'key' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $businessId = (int) $data['business_id'];
        if (! $this->businessScope->allows($request->user(), $businessId)) {
            abort(404);
        }

        $outcome = $this->gateway->execute(ToolRequest::forOperator($request->user(), QuarantineRepairTool::KEY, [
            'business_slug' => (string) Business::query()->whereKey($businessId)->value('slug'),
            'repair_key' => $data['key'],
            'reason' => $data['reason'],
        ], $businessId));

        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            throw ValidationException::withMessages(['repair' => (string) ($outcome->error ?? $outcome->policyReason ?? 'The repair was refused.')]);
        }
        $status = (string) ($outcome->output['status'] ?? '');
        if (! in_array($status, ['verified', 'already_verified'], true)) {
            $why = collect($outcome->output['splits'] ?? [])->pluck('why')->filter()->unique()->implode('; ');
            throw ValidationException::withMessages(['repair' => "The batch is {$status}".($why !== '' ? ": {$why}" : '').'. Nothing more was changed; look again and resume it.']);
        }

        return back()->with('status', 'quarantine-repaired');
    }

    /** Assign a business to a memory the repair withheld (A3 point 8). */
    public function assignMemory(Request $request, int $memory): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $this->memories->assign($memory, (int) $data['business_id'], $request->user(), $data['note'], $this->businessScope->all($request->user()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['memory' => $e->getMessage()]);
        }

        return back()->with('status', 'quarantine-memory-assigned');
    }

    /**
     * The proposed batches every business of which the person may read, with what each split would do and the
     * review state of its rows. Ids and counts only; no customer's name, phone or text.
     *
     * @param  list<int>|null  $allowed
     * @param  array<int, string>  $names
     * @return list<array<string, mixed>>
     */
    private function batches(RepairPlan $plan, ?array $allowed, array $names): array
    {
        $out = [];
        foreach ($plan->batches as $batch) {
            $splits = [];
            foreach ($batch['splits'] as $s) {
                if (! $this->within($allowed, [(int) $s['from_business_id'], (int) $s['to_business_id']])) {
                    continue 2; // a batch is applied whole, so it is offered only to a person who may read all of it
                }
                $r = $s['rows'];
                $splits[] = [
                    'key' => $s['key'],
                    'from_customer_id' => (int) $s['from_customer_id'],
                    'from_business' => $names[$s['from_business_id']] ?? null,
                    'to_customer_id' => $s['to_customer_id'],
                    'merge_id' => $s['merge_id'],
                    'counts' => [
                        'orders' => count($r['orders']), 'conversations' => count($r['conversations']), 'participants' => count($r['participants']),
                        'messages' => count($r['messages']), 'memories' => count($r['memories']), 'identities' => count($r['identities_move']),
                        'identities_copied' => count($r['identities_copy']), 'conflicts' => count($r['conflicts_resolve']),
                        'memories_withheld' => count($s['withheld']['memories_unscoped']), 'consent_rerecord' => count($s['withheld']['consent_rerecord']),
                    ],
                    'review' => $s['review'],
                ];
            }
            $out[] = [
                'key' => $batch['key'],
                'business_id' => (int) $batch['business_id'],
                'business' => $names[$batch['business_id']] ?? $batch['business_slug'],
                'authorized' => collect($splits)->every(fn (array $s) => $s['review']['authorized']),
                'splits' => $splits,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $allowed
     * @param  list<int|null>  $businessIds
     */
    private function within(?array $allowed, array $businessIds): bool
    {
        if ($allowed === null) {
            return true;
        }
        foreach ($businessIds as $id) {
            if ($id !== null && ! in_array($id, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
