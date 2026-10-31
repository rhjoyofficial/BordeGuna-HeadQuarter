<?php

namespace App\Http\Controllers\Completion;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ResearchArtifact;
use App\Models\ResearchDigest;
use App\Models\ResearchTask;
use App\Research\Digest\ResearchDigestBuilder;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use App\Support\Http\ListPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Research digests (docs/89 §3.8, docs/145-a): competitor and market notes assembled from filed, cited
 * research artifacts of the person's businesses. Reading writes nothing; building one is a person's act
 * that copies cited claims and checks every figure in the person's note against them. No search, no
 * fetch and no model call happens from this screen.
 */
final class ResearchDigestController extends Controller
{
    use RefusesPolitely;

    public function __construct(
        private readonly ResearchDigestBuilder $builder,
        private readonly BusinessScope $scope,
        private readonly Features $features,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $digestsPage = ListPage::paginate(ResearchDigest::query()->visibleTo($user)->with(['business:id,name', 'brand:id,name'])->orderByDesc('id'), $request);
        $digests = $digestsPage->getCollection()
            ->map(fn (ResearchDigest $d) => [
                'uuid' => $d->uuid, 'kind' => $d->kind, 'title' => $d->title, 'business' => $d->business?->name, 'brand' => $d->brand?->name,
                'entries' => array_values((array) $d->entries), 'note' => $d->note, 'digest' => $d->digest, 'created_at' => $d->created_at?->toIso8601String(),
            ]);

        $taskIds = $this->scope->restrict(ResearchTask::query(), $user)->pluck('id')->all();
        $artifacts = ResearchArtifact::query()->whereIn('task_id', $taskIds)->orderByDesc('id')->limit(200)->get()
            ->map(function (ResearchArtifact $a) {
                $task = ResearchTask::query()->find($a->task_id, ['id', 'business_id', 'brand_id']);
                $cited = collect((array) $a->claims)->whereIn('kind', ['fact', 'metric'])->count();

                return ['id' => $a->id, 'question' => mb_substr((string) $a->question, 0, 200), 'version' => $a->version, 'business_id' => $task?->business_id,
                    'brand_id' => $task?->brand_id, 'cited_claims' => $cited, 'as_of' => $a->as_of?->toIso8601String()];
            });

        return Inertia::render('research/digests', [
            'digests' => $digests,
            'pagination' => $digestsPage->meta(),
            'artifacts' => $artifacts,
            'engineOn' => $this->features->enabled('research.engine'),
            'options' => [
                'kinds' => ResearchDigestBuilder::KINDS,
                'businesses' => Business::query()->orderBy('name')->get(['id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->id))->values()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
                'brands' => Brand::query()->orderBy('name')->get(['id', 'business_id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->business_id))->values()->map(fn ($b) => ['id' => $b->id, 'business_id' => $b->business_id, 'name' => $b->name]),
            ],
            'can' => ['build' => $user->can('hq.research.digest')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(ResearchDigestBuilder::KINDS)],
            'title' => ['required', 'string', 'max:160'],
            'artifact_ids' => ['required', 'array', 'min:1', 'max:20'],
            'artifact_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);
        $business = Business::query()->find((int) $data['business_id']);
        $this->inScope($business, $business?->id);
        $brand = isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null;

        return $this->attempt(fn () => $this->builder->build($request->user(), $business, $brand, $data['kind'], $data['title'], $data['artifact_ids'], $data['note'] ?? null), 'digest-built');
    }
}
