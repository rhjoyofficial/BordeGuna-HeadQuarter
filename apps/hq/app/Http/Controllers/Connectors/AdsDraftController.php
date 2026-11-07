<?php

namespace App\Http\Controllers\Connectors;

use App\Ads\Draft\AdsDraftGuard;
use App\Ads\Draft\AdsDraftSpec;
use App\Models\AdsDraft;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Support\Authorization\BusinessScope;
use App\Support\Http\ListPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paused ad drafts (docs/144-meta-ads-drafts). The screen shows the guard's verdict as the person types (a read, no
 * side effect) and files the request through the gateway, where it waits for approval. There is no button anywhere
 * that switches a draft on: that is done in Ads Manager, by a person.
 */
final class AdsDraftController extends ConnectorController
{
    public function index(Request $request, BusinessScope $scope): Response
    {
        $user = $request->user();
        $businesses = Business::query()->when($scope->all($user) !== null, fn ($q) => $q->whereIn('id', (array) $scope->all($user)))->orderBy('name')->get(['id', 'slug', 'name']);

        $page = ListPage::paginate(AdsDraft::query()->visibleTo($user)->latest('id'), $request);

        return Inertia::render('ads-drafts/index', [
            'pagination' => $page->meta(),
            'drafts' => $page->getCollection()->map(fn (AdsDraft $d) => [
                'uuid' => $d->uuid, 'name' => $d->name, 'status' => $d->status, 'error' => $d->error, 'ad_account_ref' => $d->ad_account_ref,
                'daily_budget' => $d->spec['daily_budget'] ?? null, 'currency' => $d->spec['currency'] ?? null, 'platform_refs' => $d->platform_refs, 'created_at' => $d->created_at?->toIso8601String(),
            ])->all(),
            'businesses' => $businesses->map(fn (Business $b) => ['slug' => $b->slug, 'name' => $b->name])->all(),
            'accounts' => PlatformAccount::query()->where('platform', 'meta_ad_account')->whereHas('brand', fn ($q) => $q->whereIn('business_id', $businesses->pluck('id')))
                ->with('brand:id,business_id')->get()->map(fn (PlatformAccount $a) => ['ref' => (string) $a->account_ref, 'business_id' => $a->brand?->business_id,
                    'business_slug' => $businesses->firstWhere('id', $a->brand?->business_id)?->slug])->all(),
            'objectives' => AdsDraftSpec::OBJECTIVES, 'cap_usd' => (string) config('hq.ads.draft.max_daily_usd', '5.00'),
            'can' => ['draft' => $user->can('hq.tools.ads.draft')],
        ]);
    }

    /** The guard's verdict on what was typed. Creates nothing and calls nothing. */
    public function check(Request $request, AdsDraftGuard $guard): JsonResponse
    {
        try {
            $spec = $this->spec($request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json($guard->check($spec));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $business = $this->scopedBusiness($request->user(), $data['business_slug']);

        return $this->outcomeResponse($this->runTool($request->user(), 'ads.create_draft', array_filter($data, static fn ($v) => $v !== null), $business), 'name', 'ads-draft-requested');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'business_slug' => ['required', 'string', 'max:64'], 'ad_account_ref' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:120'],
            'objective' => ['required', Rule::in(AdsDraftSpec::OBJECTIVES)], 'daily_budget' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'currency' => ['required', 'in:USD,BDT'],
            'page_id' => ['required', 'regex:/^\d{5,20}$/'], 'link_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'primary_text' => ['required', 'string', 'max:1000'], 'headline' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:200'],
            'image_hash' => ['nullable', 'string', 'max:64'], 'claim_proofs' => ['nullable', 'array'], 'claim_proofs.*' => ['string', 'max:200'],
        ]);
    }

    private function spec(Request $request): AdsDraftSpec
    {
        $d = $this->validated($request);

        return new AdsDraftSpec($d['name'], $d['objective'], $d['daily_budget'], $d['currency'], $d['page_id'], $d['link_url'], $d['primary_text'], $d['headline'],
            $d['description'] ?? null, $d['image_hash'] ?? null, ['BD'], array_map('strval', $d['claim_proofs'] ?? []));
    }
}
