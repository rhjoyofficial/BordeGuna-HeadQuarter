<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\JobRun;
use App\Models\PlatformAccount;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Audit\Auditor;
use App\Support\Credentials\CredentialWriter;
use App\Support\Features\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 0 admin screens: dashboard, audit log, feature flags, credentials, domain model. */
final class AdminController extends Controller
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Features $features,
        private readonly CredentialWriter $credentials,
    ) {}

    public function dashboard(): Response
    {
        return Inertia::render('dashboard', [
            'counts' => [
                'businesses' => Business::count(), 'brands' => Brand::count(), 'stores' => Store::count(),
                'platform_accounts' => PlatformAccount::count(), 'credentials' => IntegrationCredential::count(),
                'jobs_active' => ScheduledJob::where('state', 'active')->count(),
                'runs_24h' => JobRun::where('created_at', '>=', now()->subDay())->count(),
                'runs_failed_24h' => JobRun::whereIn('status', ['failed', 'dead'])->where('created_at', '>=', now()->subDay())->count(),
                'events_pending' => DomainEvent::whereIn('status', ['pending', 'failed'])->count(),
                'events_dead' => DomainEvent::where('status', 'dead')->count(),
                'audit_24h' => AuditLog::where('occurred_at', '>=', now()->subDay())->count(),
            ],
            'scheduler' => ['last_tick' => Cache::get(SchedulerTick::HEARTBEAT_KEY)],
            'recentAudit' => AuditLog::latest('occurred_at')->limit(10)->get(),
        ]);
    }

    public function audit(Request $request): Response
    {
        $logs = AuditLog::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('actor_type'), fn ($q) => $q->where('actor_type', $request->string('actor_type')))
            ->latest('occurred_at')->paginate(50)->withQueryString();

        return Inertia::render('audit/index', ['logs' => $logs, 'filters' => $request->only('action', 'actor_type')]);
    }

    public function flags(): Response
    {
        return Inertia::render('flags/index', ['flags' => FeatureFlag::orderBy('key')->get(), 'environment' => app()->environment()]);
    }

    public function storeFlag(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z0-9_.-]{3,96}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', 'in:*,production,staging,local'],
            'enabled' => ['boolean'],
        ]);
        $flag = FeatureFlag::updateOrCreate(['key' => $data['key'], 'environment' => $data['environment']], ['description' => $data['description'] ?? null, 'enabled' => (bool) ($data['enabled'] ?? false)]);
        $this->features->forget();
        $this->auditor->record('feature_flag.saved', $flag, after: $flag->toArray());

        return back()->with('status', 'flag-saved');
    }

    public function toggleFlag(FeatureFlag $flag): RedirectResponse
    {
        $before = $flag->toArray();
        $flag->enabled = ! $flag->enabled;
        $flag->save();
        $this->features->forget();
        $this->auditor->record('feature_flag.toggled', $flag, before: $before, after: $flag->toArray());

        return back()->with('status', 'flag-toggled');
    }

    public function credentials(): Response
    {
        return Inertia::render('credentials/index', [
            'credentials' => IntegrationCredential::with(['business:id,name', 'brand:id,name'])->orderBy('provider')->get()->map(fn ($c) => $c->toSafeArray() + ['business' => $c->business?->name, 'brand' => $c->brand?->name]),
            'businesses' => Business::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'account_ref' => ['nullable', 'string', 'max:255'],
            'business_id' => ['nullable', 'exists:businesses,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'credential_type' => ['required', 'in:api_key,token,basic,custom'],
            'secret' => ['required', 'string', 'max:8192'],
            'scopes' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);
        // Storing is REPLACING. The writer revokes whatever was active for this provider/account
        // first, in one transaction, so this screen can never leave two active rows for one
        // identity - the state that made a replaced key keep working during the M4 safety gate.
        $this->credentials->replace(
            provider: $data['provider'],
            accountRef: $data['account_ref'] ?? null,
            payload: ['value' => $data['secret']],
            attributes: collect($data)->except('secret', 'provider', 'account_ref')->all(),
        );

        return back()->with('status', 'credential-stored');
    }

    public function revokeCredential(IntegrationCredential $credential): RedirectResponse
    {
        $before = $credential->toSafeArray();
        $credential->forceFill(['status' => 'revoked', 'payload' => null])->save();
        $this->auditor->record('credential.revoked', $credential, before: $before, after: $credential->toSafeArray());

        return back()->with('status', 'credential-revoked');
    }

    public function businesses(): Response
    {
        return Inertia::render('businesses/index', [
            'businesses' => Business::with(['brands.platformAccounts', 'stores.productOwners:id,name'])->orderBy('name')->get(),
        ]);
    }
}
