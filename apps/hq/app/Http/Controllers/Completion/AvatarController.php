<?php

namespace App\Http\Controllers\Completion;

use App\Avatar\AvatarConsents;
use App\Avatar\AvatarGenerations;
use App\Avatar\AvatarProvider;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Models\AvatarConsent;
use App\Models\AvatarGeneration;
use App\Models\Brand;
use App\Models\Business;
use App\Models\MediaAsset;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use App\Tools\Avatar\GenerateAvatarTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The avatar consent registry (docs/20, docs/145-b). Record a written consent, revoke it at once, see what
 * every generation rested on. Asking for a clip is a gateway request for `avatar.generate` as the signed-in
 * person: R4, approval required, permanently human-only, and registered nowhere today, so the gateway refuses.
 */
final class AvatarController extends Controller
{
    use RefusesPolitely;

    public function __construct(
        private readonly AvatarConsents $consents,
        private readonly BusinessScope $scope,
        private readonly Features $features,
        private readonly ToolGateway $gateway,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $brandNames = Brand::query()->pluck('name', 'id')->all();

        $consents = AvatarConsent::query()->visibleTo($user)->with('business:id,name')->orderByDesc('id')->limit(200)->get()
            ->map(fn (AvatarConsent $c) => [
                'uuid' => $c->uuid, 'business' => $c->business?->name, 'business_id' => $c->business_id, 'person_label' => $c->person_label, 'kind' => $c->kind,
                'modalities' => array_values((array) $c->modalities), 'permitted_uses' => array_values((array) $c->permitted_uses),
                'brands' => array_values(array_map(fn ($id) => ['id' => (int) $id, 'name' => $brandNames[(int) $id] ?? "#{$id}"], (array) $c->brand_ids)),
                'has_document' => $c->consent_document_asset_id !== null, 'signed_at' => $c->signed_at?->toIso8601String(),
                'expires_at' => $c->expires_at?->toIso8601String(), 'state' => AvatarConsents::state($c),
                'revoked_at' => $c->revoked_at?->toIso8601String(), 'revocation_reason' => $c->revocation_reason,
            ]);

        $generations = AvatarGeneration::query()->visibleTo($user)->with('consent:id,uuid,person_label')->orderByDesc('id')->limit(100)->get()
            ->map(fn (AvatarGeneration $g) => [
                'uuid' => $g->uuid, 'consent' => $g->consent?->person_label, 'brand' => $brandNames[(int) $g->brand_id] ?? null, 'use' => $g->use, 'modality' => $g->modality,
                'status' => $g->status, 'reason' => $g->refusal_reason, 'provider' => $g->provider, 'created_at' => $g->created_at?->toIso8601String(),
            ]);

        $documents = MediaAsset::query()->whereIn('kind', ['document', 'image'])->where('provenance', 'business')
            ->when($this->scope->all($user) !== null, fn ($q) => $q->whereIn('business_id', $this->scope->all($user)))
            ->orderByDesc('id')->limit(100)->get(['id', 'business_id', 'kind', 'mime', 'created_at'])
            ->map(fn (MediaAsset $m) => ['id' => $m->id, 'business_id' => $m->business_id, 'label' => "#{$m->id} {$m->kind} ({$m->mime})"]);

        return Inertia::render('avatar/index', [
            'consents' => $consents,
            'generations' => $generations,
            'posture' => [
                'generation_flag' => $this->features->enabled(AvatarGenerations::FLAG),
                'provider_connected' => app()->bound(AvatarProvider::class),
                'tool_registered' => in_array(GenerateAvatarTool::class, (array) config('hq.tools', []), true),
            ],
            'options' => [
                'kinds' => AvatarConsents::KINDS, 'modalities' => AvatarConsents::MODALITIES, 'uses' => AvatarConsents::USES, 'max_months' => AvatarConsents::MAX_MONTHS,
                'businesses' => Business::query()->orderBy('name')->get(['id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->id))->values()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
                'brands' => Brand::query()->orderBy('name')->get(['id', 'business_id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->business_id))->values()->map(fn ($b) => ['id' => $b->id, 'business_id' => $b->business_id, 'name' => $b->name]),
                'documents' => $documents,
            ],
            'can' => ['manage' => $user->can('hq.avatar.consent.manage'), 'generate' => $user->can(GenerateAvatarTool::PERMISSION)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'person_label' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(AvatarConsents::KINDS)],
            'modalities' => ['required', 'array', 'min:1'],
            'modalities.*' => [Rule::in(AvatarConsents::MODALITIES)],
            'permitted_uses' => ['required', 'array', 'min:1'],
            'permitted_uses.*' => [Rule::in(AvatarConsents::USES)],
            'brand_ids' => ['required', 'array', 'min:1'],
            'brand_ids.*' => ['integer'],
            'consent_document_asset_id' => ['nullable', 'integer'],
            'signed_at' => ['nullable', 'date'],
            'expires_at' => ['required', 'date'],
        ]);
        $business = Business::query()->find((int) $data['business_id']);
        $this->inScope($business, $business?->id);

        return $this->attempt(fn () => $this->consents->record($request->user(), $business, $data), 'avatar-consent-recorded');
    }

    public function revoke(Request $request, AvatarConsent $consent): RedirectResponse
    {
        $this->inScope($consent, (int) $consent->business_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->consents->revoke($request->user(), $consent, $data['reason']), 'avatar-consent-revoked');
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'consent_uuid' => ['required', 'string', 'max:64'],
            'brand_id' => ['required', 'integer'],
            'use' => ['required', Rule::in(AvatarConsents::USES)],
            'modality' => ['required', Rule::in(AvatarConsents::MODALITIES)],
            'script' => ['required', 'string', 'max:2000'],
        ]);
        $consent = AvatarConsent::query()->where('uuid', $data['consent_uuid'])->first();
        $this->inScope($consent, $consent?->business_id);

        $outcome = $this->gateway->execute(ToolRequest::forOperator($request->user(), GenerateAvatarTool::KEY, [
            'business_slug' => (string) Business::query()->whereKey($consent->business_id)->value('slug'),
            'consent_uuid' => $consent->uuid,
            'brand_id' => (int) $data['brand_id'],
            'use' => $data['use'],
            'modality' => $data['modality'],
            'script' => $data['script'],
        ], (int) $consent->business_id));

        if ($outcome->status === OutcomeStatus::AwaitingApproval) {
            return back()->with('status', 'avatar-generation-awaiting-approval');
        }
        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            throw ValidationException::withMessages(['generate' => (string) ($outcome->error ?? $outcome->policyReason ?? 'The generation was refused.')]);
        }

        return back()->with('status', 'avatar-generated');
    }
}
