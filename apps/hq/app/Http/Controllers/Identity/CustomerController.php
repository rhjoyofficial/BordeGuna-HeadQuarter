<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
use App\Models\Customer;
use App\Models\CustomerMergeCandidate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Customer identity screens: profiles, identities, merge candidates, human-decided merges. */
final class CustomerController extends Controller
{
    public function __construct(private readonly IdentityResolver $identity) {}

    public function index(Request $request): Response
    {
        $customers = Customer::query()->with('business:id,name')->whereNull('merged_into_id')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->string('q'));
                $phone = PhoneCanonical::normalize($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")->when($phone, fn ($x) => $x->orWhere('primary_phone_canonical', $phone)));
            })
            ->orderByDesc('last_seen_at')->paginate(25)->withQueryString();

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'candidates' => CustomerMergeCandidate::with(['customer:id,name,primary_phone_canonical,email', 'candidate:id,name,primary_phone_canonical,email'])->where('status', 'pending')->latest()->limit(50)->get(),
            'filters' => ['q' => $request->string('q')->toString()],
        ]);
    }

    public function show(Customer $customer): Response
    {
        $customer = $customer->canonical();

        return Inertia::render('customers/show', [
            'customer' => $customer->load(['business:id,name', 'identities']),
            'orders' => $customer->orders()->with('store:id,name')->orderByDesc('placed_at')->limit(50)->get(),
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate(['kept_id' => ['required', 'exists:customers,id'], 'absorbed_id' => ['required', 'different:kept_id', 'exists:customers,id'], 'reason' => ['nullable', 'string', 'max:191']]);
        $this->identity->merge(Customer::findOrFail($data['kept_id']), Customer::findOrFail($data['absorbed_id']), $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', 'customers-merged');
    }

    public function dismiss(Request $request, CustomerMergeCandidate $candidate): RedirectResponse
    {
        $candidate->forceFill(['status' => 'dismissed', 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();

        return back()->with('status', 'candidate-dismissed');
    }
}
