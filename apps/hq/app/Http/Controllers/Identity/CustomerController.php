<?php

namespace App\Http\Controllers\Identity;

use App\Customer360\Customer360Reader;
use App\Http\Controllers\Controller;
use App\Identity\CrossBusinessMerge;
use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
use App\Identity\Quarantine\CrossBusinessSuppression;
use App\Models\Customer;
use App\Models\CustomerMergeCandidate;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer identity screens: profiles, identities, merge candidates, human-decided merges, and the
 * read-only Customer 360 page (docs/109).
 *
 * ⚠ Business scope (docs/48 E.4): a person restricted to some businesses sees only their customers. A
 * customer of another business answers 404, not 403, like a thread on the inbox: it does not exist for you.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly BusinessScope $businessScope,
        private readonly CrossBusinessSuppression $suppression,
    ) {}

    /** Customers of the businesses this person may see. Nobody signed in sees none. */
    private function readable(Request $request): Builder
    {
        $user = $request->user();

        return $user === null ? Customer::query()->whereRaw('1 = 0') : $this->businessScope->restrict(Customer::query(), $user);
    }

    private function assertReadable(Request $request, Customer $customer): void
    {
        if (! $this->readable($request)->whereKey($customer->getKey())->exists()) {
            abort(404);
        }
    }

    public function index(Request $request): Response
    {
        // A row absorbed by a merge is hidden behind its survivor, unless that merge crossed a business
        // (docs/117): then the survivor is not this business's, and the row stays listed in its own business.
        $customers = $this->readable($request)->with('business:id,name')
            ->where(fn ($w) => $w->whereNull('merged_into_id')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('customers as merged_target')
                ->whereColumn('merged_target.id', 'customers.merged_into_id')->whereColumn('merged_target.business_id', '!=', 'customers.business_id')))
            // An explicit business narrows the search to it (still inside what this person may read).
            ->when($request->integer('business') > 0, fn ($q) => $q->where('business_id', $request->integer('business')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->string('q'));
                $phone = PhoneCanonical::normalize($term);
                // ⛔ Ruling docs/120 item 38: a withheld value (a phone or e-mail that may have come from another
                // business) is never matched, so a search by it reveals no customer, here or anywhere else.
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")
                    ->orWhere(fn ($e) => $e->where('email', 'ilike', "%{$term}%")->whereRaw('NOT '.CrossBusinessSuppression::emailWithheldSql('customers')))
                    ->when($phone, fn ($x) => $x->orWhere(fn ($p) => $p->where('primary_phone_canonical', $phone)->whereRaw('NOT '.CrossBusinessSuppression::phoneWithheldSql('customers')))));
            })
            ->orderByDesc('last_seen_at')->paginate(25)->withQueryString();
        // docs/117: the same cross-business suppression as the 360 page, applied server-side to every row.
        $touched = $this->suppression->touched($customers->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());
        $customers->through(fn (Customer $c) => $this->suppression->listRow($c, $touched));

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'candidates' => CustomerMergeCandidate::with(['customer:id,name,primary_phone_canonical,email', 'candidate:id,name,primary_phone_canonical,email'])
                ->whereIn('customer_id', $this->readable($request)->select('id'))
                ->whereIn('candidate_id', $this->readable($request)->select('id'))
                // Only pairs of one business: a candidate across businesses is never offered (ruling docs/103 §11).
                ->whereExists(fn ($e) => $e->selectRaw('1')->from('customers as cand_a')->join('customers as cand_b', 'cand_b.business_id', '=', 'cand_a.business_id')
                    ->whereColumn('cand_a.id', 'customer_merge_candidates.customer_id')->whereColumn('cand_b.id', 'customer_merge_candidates.candidate_id'))
                ->where('status', 'pending')->latest()->limit(50)->get(),
            'filters' => ['q' => $request->string('q')->toString(), 'business' => $request->integer('business') ?: null],
        ]);
    }

    /**
     * Customer 360 (docs/109, ruling docs/103 §5): read-only and business-scoped. Both the requested row
     * and the surviving row a merge led to must be readable; the page itself writes nothing. A merge is
     * followed only within one business (docs/117): a link into another business is held, not followed.
     */
    public function show(Request $request, Customer $customer, Customer360Reader $reader): Response
    {
        $this->assertReadable($request, $customer);
        $customer = $this->suppression->subject($customer);
        $this->assertReadable($request, $customer);

        return Inertia::render('customers/show', $reader->read($customer, $request->user()));
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate(['kept_id' => ['required', 'exists:customers,id'], 'absorbed_id' => ['required', 'different:kept_id', 'exists:customers,id'], 'reason' => ['nullable', 'string', 'max:191']]);
        $kept = Customer::findOrFail($data['kept_id']);
        $absorbed = Customer::findOrFail($data['absorbed_id']);
        $this->assertReadable($request, $kept);
        $this->assertReadable($request, $absorbed);

        try {
            $this->identity->merge($kept, $absorbed, $request->user()->id, $data['reason'] ?? null);
        } catch (CrossBusinessMerge $e) {
            throw ValidationException::withMessages(['absorbed_id' => $e->getMessage()]);
        }

        return back()->with('status', 'customers-merged');
    }

    public function dismiss(Request $request, CustomerMergeCandidate $candidate): RedirectResponse
    {
        $this->assertReadable($request, Customer::findOrFail($candidate->customer_id));

        $candidate->forceFill(['status' => 'dismissed', 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();

        return back()->with('status', 'candidate-dismissed');
    }
}
