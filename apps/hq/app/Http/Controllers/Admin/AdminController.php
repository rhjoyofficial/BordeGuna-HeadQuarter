<?php

namespace App\Http\Controllers\Admin;

use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Readiness\CapabilityReadiness;
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
use App\Models\User;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Support\Credentials\CredentialKinds;
use App\Support\Credentials\CredentialWriter;
use App\Support\Features\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        // docs/136: the audit log carries no business, so it is group-wide by nature and a restricted
        // person gets none of it here (ADR-035 section 4, fail closed).
        $groupWide = $user === null || ! $this->businessScope->isRestricted($user);
        $runs = fn () => $user === null ? JobRun::query() : JobRun::query()->visibleTo($user);

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
                    ->whereIn('status', ['answered', 'refused', 'clarified', 'stopped'])->count(),
                'href' => '/agents?filter=unreviewed',
                'permission' => 'hq.agents.view',
                'tone' => 'pending',
            ],
            [
                'key' => 'jobs_failed',
                'label' => 'Scheduled runs that failed in the last day',
                'count' => $runs()->whereIn('status', ['failed', 'dead'])->where('created_at', '>=', $since)->count(),
                'href' => '/scheduler',
                'permission' => 'hq.jobs.view',
                'tone' => 'danger',
            ],
            [
                'key' => 'events_dead',
                'label' => 'Events that could not be processed',
                'count' => $scoped(DomainEvent::query())->where('status', 'dead')->count(),
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
        // docs/136: `connector_calls` carries no business and no content; a machinery health count, like the heartbeat.
        $connectorCalls = ConnectorCall::query()->where('created_at', '>=', $since)
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
                'pending' => $scoped(DomainEvent::query())->whereIn('status', ['pending', 'failed'])->count(),
                'dead' => $scoped(DomainEvent::query())->where('status', 'dead')->count(),
                'last_at' => $scoped(DomainEvent::query())->max('occurred_at'),
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
                'businesses' => $scoped(Business::query(), 'id')->count(), 'brands' => $scoped(Brand::query())->count(), 'stores' => $scoped(Store::query())->count(),
                'platform_accounts' => PlatformAccount::query()->whereIn('brand_id', $scoped(Brand::query())->select('id'))->count(), 'credentials' => $scoped(IntegrationCredential::query())->count(),
                'jobs_active' => $scoped(ScheduledJob::query())->where('state', 'active')->count(),
                'runs_24h' => $runs()->where('created_at', '>=', now()->subDay())->count(),
                'runs_failed_24h' => $runs()->whereIn('status', ['failed', 'dead'])->where('created_at', '>=', now()->subDay())->count(),
                'events_pending' => $scoped(DomainEvent::query())->whereIn('status', ['pending', 'failed'])->count(),
                'events_dead' => $scoped(DomainEvent::query())->where('status', 'dead')->count(),
                'audit_24h' => $groupWide ? AuditLog::where('occurred_at', '>=', now()->subDay())->count() : 0,
            ],
            'scheduler' => ['last_tick' => $lastTick],
            'recentAudit' => $groupWide ? AuditLog::latest('occurred_at')->limit(10)->get() : [],
        ]);
    }

    public function audit(Request $request): Response
    {
        $this->assertGroupWide($request);

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

    public function credentials(Request $request, CapabilityReadiness $readiness, CredentialHealth $health): Response
    {
        $user = $request->user();

        return Inertia::render('credentials/index', [
            // docs/136: a credential of another business, or of none (the model provider's key), is not shown
            // to a person whose access is limited to some businesses.
            'credentials' => IntegrationCredential::query()->visibleTo($user)->with(['business:id,name', 'brand:id,name'])->orderBy('provider')->get()
                ->map(fn ($c) => $c->toSafeArray() + [
                    'business' => $c->business?->name, 'brand' => $c->brand?->name,
                    // What the provider last said about it (ADR-043): ok or a class, when, from where. Never the value.
                    'last_result' => $health->lastVerification($c),
                ]),
            'businesses' => $this->businessScope->restrict(Business::query(), $user, 'id')->orderBy('name')->get(['id', 'name']),
            'brands' => $this->businessScope->restrict(Brand::query(), $user)->orderBy('name')->get(['id', 'name']),
            // Whether HQ could execute against each store, ERP business and the model provider right now.
            'readiness' => $this->visibleReadiness($user, array_map(fn ($row) => $row->toArray(), $readiness->inventory())),
            // docs/95 §5: the only kinds the store accepts, each with the account reference its reader uses.
            'kinds' => CredentialKinds::options(),
        ]);
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Only a kind the code reads (docs/95 §5): a row under any other name would be silently useless.
            'provider' => ['required', 'string', Rule::in(CredentialKinds::keys())],
            'account_ref' => ['nullable', 'string', 'max:255'],
            'business_id' => ['nullable', 'exists:businesses,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'credential_type' => ['required', 'in:api_key,token,basic,custom'],
            'secret' => ['required', 'string', 'max:8192'],
            'scopes' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);
        // docs/136: a restricted person stores a credential only for one of their businesses.
        $scope = $this->businessScope->all($request->user());
        if ($scope !== null && (! isset($data['business_id']) || ! in_array((int) $data['business_id'], $scope, true))) {
            throw ValidationException::withMessages(['business_id' => 'Choose one of your businesses: your access is limited to some businesses.']);
        }
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

    public function revokeCredential(Request $request, IntegrationCredential $credential): RedirectResponse
    {
        // docs/136: another business's credential is not there.
        abort_unless(IntegrationCredential::query()->visibleTo($request->user())->whereKey($credential->getKey())->exists(), 404);

        $before = $credential->toSafeArray();
        $credential->forceFill(['status' => 'revoked', 'payload' => null])->save();
        $this->auditor->record('credential.revoked', $credential, before: $before, after: $credential->toSafeArray());

        return back()->with('status', 'credential-revoked');
    }

    public function businesses(Request $request): Response
    {
        return Inertia::render('businesses/index', [
            // docs/136: the businesses this person may see, as the dashboard's business cards already were.
            'businesses' => $this->businessScope->restrict(Business::query(), $request->user(), 'id')->with(['brands.platformAccounts', 'stores.productOwners:id,name'])->orderBy('name')->get(),
        ]);
    }

    /**
     * docs/136: a screen whose rows carry no business (the audit log) is group-wide by nature, and a
     * person whose access is limited to some businesses is refused it with the reason (ADR-035 section 4).
     */
    private function assertGroupWide(Request $request): void
    {
        abort_if($this->businessScope->isRestricted($request->user()), 403, 'This screen is group-wide and your access is limited to some businesses.');
    }

    /**
     * The readiness rows of the stores and businesses this person may see. The model provider row
     * belongs to no business and is left out for a restricted person (docs/136).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function visibleReadiness(User $user, array $rows): array
    {
        $scope = $this->businessScope->all($user);
        if ($scope === null) {
            return $rows;
        }
        $businesses = Business::query()->whereIn('id', $scope)->pluck('slug')->all();
        $stores = Store::query()->whereIn('business_id', $scope)->pluck('slug')->all();

        return array_values(array_filter($rows, fn (array $row) => match ($row['target_kind'] ?? null) {
            'store' => in_array($row['target'], $stores, true),
            'business' => in_array($row['target'], $businesses, true),
            default => false,
        }));
    }
}
