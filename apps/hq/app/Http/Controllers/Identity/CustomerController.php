<?php

namespace App\Http\Controllers\Identity;

use App\Customer360\Customer360Reader;
use App\Http\Controllers\Controller;
use App\Identity\CrossBusinessMerge;
use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
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
        $customers = $this->readable($request)->with('business:id,name')->whereNull('merged_into_id')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->string('q'));
                $phone = PhoneCanonical::normalize($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")->when($phone, fn ($x) => $x->orWhere('primary_phone_canonical', $phone)));
            })
            ->orderByDesc('last_seen_at')->paginate(25)->withQueryString();

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'candidates' => CustomerMergeCandidate::with(['customer:id,name,primary_phone_canonical,email', 'candidate:id,name,primary_phone_canonical,email'])
                ->whereIn('customer_id', $this->readable($request)->select('id'))
                ->whereIn('candidate_id', $this->readable($request)->select('id'))
                ->where('status', 'pending')->latest()->limit(50)->get(),
            'filters' => ['q' => $request->string('q')->toString()],
        ]);
    }

    /**
     * Customer 360 (docs/109, ruling docs/103 §5): read-only and business-scoped. Both the requested row
     * and the surviving row a merge led to must be readable; the page itself writes nothing.
     */
    public function show(Request $request, Customer $customer, Customer360Reader $reader): Response
    {
        $this->assertReadable($request, $customer);
        $customer = $customer->canonical();
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
