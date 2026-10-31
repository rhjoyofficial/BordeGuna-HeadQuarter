<?php

namespace App\Http\Controllers\Connectors;

use App\Media\MediaAssetService;
use App\Media\Pipeline\MediaJobSpec;
use App\Models\Business;
use App\Models\MediaDraft;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Reel and video drafts (docs/144-media-pipeline): ask for one, look at it, approve or reject it. Approving only records
 * a person's yes on the draft; it publishes nothing (publishing is the content gate's own approval, and an asset whose
 * rights nobody recorded cannot be published at all).
 */
final class MediaDraftController extends ConnectorController
{
    public function index(Request $request, BusinessScope $scope): Response
    {
        $user = $request->user();

        return Inertia::render('media-drafts/index', [
            'drafts' => MediaDraft::query()->visibleTo($user)->with('business:id,name')->latest('id')->limit(50)->get()->map(fn (MediaDraft $d) => [
                'uuid' => $d->uuid, 'business' => $d->business?->name, 'brief' => $d->brief, 'status' => $d->status, 'provider' => $d->provider, 'error' => $d->error,
                'review_note' => $d->review_note, 'has_video' => $d->result_media_asset_id !== null, 'created_at' => $d->created_at?->toIso8601String(),
            ])->all(),
            'businesses' => Business::query()->when($scope->all($user) !== null, fn ($q) => $q->whereIn('id', (array) $scope->all($user)))->orderBy('name')->get(['slug', 'name'])->all(),
            'aspects' => MediaJobSpec::ASPECTS, 'max_seconds' => MediaJobSpec::MAX_SECONDS,
            'can' => ['request' => $user->can('hq.tools.media.draft'), 'review' => $user->can('hq.tools.media.draft')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_slug' => ['required', 'string', 'max:64'], 'brief' => ['required', 'string', 'max:2000'],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:'.MediaJobSpec::MAX_SECONDS], 'aspect' => ['nullable', 'in:'.implode(',', MediaJobSpec::ASPECTS)],
        ]);
        $business = $this->scopedBusiness($request->user(), $data['business_slug']);

        return $this->outcomeResponse($this->runTool($request->user(), 'media.draft_reel', array_filter($data, static fn ($v) => $v !== null), $business), 'brief', 'media-draft-requested');
    }

    public function refresh(Request $request, string $uuid): RedirectResponse
    {
        $draft = $this->find($request, $uuid);
        $business = $this->scopedBusiness($request->user(), (string) Business::query()->whereKey($draft->business_id)->value('slug'));

        return $this->outcomeResponse($this->runTool($request->user(), 'media.check_draft', ['business_slug' => $business->slug, 'draft_uuid' => $draft->uuid], $business), 'draft', 'media-draft-checked');
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $draft = $this->find($request, $uuid);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:500']]);
        if ($draft->status !== 'ready') {
            throw ValidationException::withMessages(['decision' => 'Only a finished draft can be approved or rejected.']);
        }
        $draft->forceFill(['status' => $data['decision'], 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null])->save();
        app(Auditor::class)->record('media.draft_'.$data['decision'], $draft, ['status' => 'ready'], ['status' => $data['decision']], $data['note'] ?? null, context: ['business_id' => $draft->business_id]);

        return back()->with('status', 'media-draft-'.$data['decision']);
    }

    public function video(Request $request, string $uuid, MediaAssetService $assets): HttpResponse
    {
        $draft = $this->find($request, $uuid);
        abort_if($draft->result === null, 404);
        $bytes = $assets->contents($draft->result);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $draft->result->mime, 'Content-Disposition' => 'inline; filename="draft.mp4"', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function find(Request $request, string $uuid): MediaDraft
    {
        $draft = MediaDraft::query()->visibleTo($request->user())->where('uuid', $uuid)->first();
        abort_if($draft === null, 404);

        return $draft;
    }
}
