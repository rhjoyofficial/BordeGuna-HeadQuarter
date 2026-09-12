<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ConnectorCall;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\JobRun;
use App\Models\PlatformAccount;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Models\ToolRegistration;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
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
        private readonly BusinessScope $businessScope,
    ) {}

    /**
     * The landing screen answers "what needs me" before it shows anything else.
     *
     * `attention` is the list of things a person has to act on, each with a count and the screen
     * that resolves it; `health` is whether the machinery is running; `businesses` is one card per
     * business with its stores and how recently an order arrived. The Phase 0 `counts` stay, at the
     * bottom. Counts that describe rows a restricted operator may not see are computed inside the
     * same business scope the screens use.
     */
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $scoped = fn ($query, string $column = 'business_id') => $user === null ? $query : $this->businessScope->restrict($query, $user, $column);
        $since = now()->subDay();

        $attention = [
            [
                'key' => 'approvals_pending',
                'label' => 'Approvals waiting for a decision',
                'count' => $scoped(Approval::pending())->count(),
                'href' => '/approvals',
                'permission' => 'hq.approvals.view',
                'tone' => 'pending',
            ],
            [
                'key' => 'approvals_overdue',
                'label' => 'Approvals past their deadline',
                'count' => $scoped(Approval::pastSla())->count(),
                'href' => '/approvals',
                'permission' => 'hq.approvals.view',
                'tone' => 'warn',
            ],
            [
                'key' => 'runs_to_review',
                'label' => 'Shadow runs to read',
                'count' => $scoped(AgentRun::query())->where('shadow', true)->whereNull('reviewed_at')
                    ->whereIn('status', ['answered', 'refused', 'stopped'])->count(),
                'href' => '/agents?filter=unreviewed',
                'permission' => 'hq.agents.view',
                'tone' => 'pending',
            ],
            [
                'key' => 'jobs_failed',
                'label' => 'Scheduled runs that failed in the last day',
                'count' => JobRun::whereIn('status', ['failed', 'dead'])->where('created_at', '>=', $since)->count(),
                'href' => '/scheduler',
                'permission' => 'hq.jobs.view',
                'tone' => 'danger',
            ],
            [
                'key' => 'events_dead',
                'label' => 'Events that could not be processed',
                'count' => DomainEvent::where('status', 'dead')->count(),
                'href' => '/audit?action=event',
                'permission' => 'hq.audit.view',
                'tone' => 'danger',
            ],
            [
                'key' => 'tools_disabled',
                'label' => 'Tools switched off by hand',
                'count' => ToolRegistration::where('enabled', false)->count(),
                'href' => '/tools?state=disabled',
                'permission' => 'hq.tools.view',
                'tone' => 'warn',
            ],
        ];

        $lastTick = Cache::get(SchedulerTick::HEARTBEAT_KEY);
        $connectorCalls = ConnectorCall::where('created_at', '>=', $since)
            ->selectRaw("count(*) as total, sum(case when status = 'succeeded' then 1 else 0 end) as ok")
            ->first();

        $health = [
            'scheduler' => [
                'last_tick' => $lastTick,
                'stale_after_seconds' => 180,
            ],
            'ai_runtime' => $this->features->enabled('ai.runtime'),
            'agents' => Agent::query()->orderBy('key')->get(['key', 'name', 'status', 'max_autonomy_level'])
                ->map(fn (Agent $a) => ['key' => $a->key, 'name' => $a->name, 'status' => $a->status, 'autonomy' => $a->max_autonomy_level?->value])
                ->values(),
            'events' => [
                'pending' => DomainEvent::whereIn('status', ['pending', 'failed'])->count(),
                'dead' => DomainEvent::where('status', 'dead')->count(),
                'last_at' => DomainEvent::max('occurred_at'),
            ],
            'connectors_24h' => [
                'total' => (int) ($connectorCalls?->total ?? 0),
                'ok' => (int) ($connectorCalls?->ok ?? 0),
            ],
        ];

        $businesses = $scoped(Business::query(), 'id')->with(['stores:id,business_id,name,adapter,is_active', 'brands:id,business_id,name'])
            ->orderBy('name')->get()
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'is_active' => $b->is_active,
                'timezone' => $b->timezone,
                'brands' => $b->brands->pluck('name')->values(),
                'stores' => $b->stores->map(fn (Store $s) => ['name' => $s->name, 'adapter' => $s->adapter, 'is_active' => $s->is_active])->values(),
                'orders_24h' => FactsOrder::where('business_id', $b->id)->where('placed_at', '>=', $since)->count(),
                'last_order_at' => FactsOrder::where('business_id', $b->id)->max('placed_at'),
            ])
            ->values();

        return Inertia::render('dashboard', [
            'attention' => $attention,
            'health' => $health,
            'businesses' => $businesses,
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
            'scheduler' => ['last_tick' => $lastTick],
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
