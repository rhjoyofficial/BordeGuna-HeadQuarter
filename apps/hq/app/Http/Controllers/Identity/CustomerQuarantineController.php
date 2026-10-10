<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\Quarantine\QuarantineDecision;
use App\Identity\Quarantine\QuarantineLedger;
use App\Models\Business;
use App\Models\CrossBusinessQuarantine;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * docs/117 §5 (ruling docs/110 §5 (m)): the human review of quarantine flags on cross-business
 * relationships. A review is an audited, append-only decision row; it lifts no suppression and repairs
 * nothing (the lineage-preserving un-merge is designed in docs/117 §6 and waits for a ruling).
 *
 * ⚠ Business scope: a flag joins two businesses, so a person sees (and may review) it only when BOTH are
 * theirs. Any other flag answers 404, like every record you may not see.
 */
final class CustomerQuarantineController extends Controller
{
    public function __construct(
        private readonly QuarantineLedger $ledger,
        private readonly BusinessScope $businessScope,
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
        $flags = $this->readable($request->user())
            ->with(['latestReview.reviewer:id,email'])
            ->withCount('reviews')
            ->orderBy('id')
            ->limit(500)
            ->get();
        $businesses = Business::query()->pluck('name', 'id');

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
}
