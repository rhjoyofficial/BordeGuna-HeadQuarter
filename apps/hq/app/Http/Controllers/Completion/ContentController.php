<?php

namespace App\Http\Controllers\Completion;

use App\Ai\Pause\AiPause;
use App\Content\Calendar\CaptionDrafter;
use App\Content\Calendar\ContentCalendar;
use App\Content\ClaimsFloor;
use App\Content\ClaimsRules;
use App\Content\ContentDraftService;
use App\Content\Publishing\PublicationService;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ContentCalendarEntry;
use App\Models\ContentClaimsRule;
use App\Models\ContentDraft;
use App\Models\ContentPublication;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content screen (docs/89 §3.7, docs/145-a): the calendar per brand, the drafts made for it, the owner's
 * claims rules and the floor. Every write is a named person's act through `ContentCalendar` or `ClaimsRules`.
 *
 * ⛔ No route here publishes, takes a tool key, a provider or an account. Publishing is the gateway tool
 * `publishing.publish_post` (R5, its own approval of the exact post), registered nowhere today.
 */
final class ContentController extends Controller
{
    use RefusesPolitely;

    public function __construct(
        private readonly ContentCalendar $calendar,
        private readonly ClaimsRules $rules,
        private readonly BusinessScope $scope,
        private readonly Features $features,
        private readonly AiPause $pause,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $entries = ContentCalendarEntry::query()->visibleTo($user)->with(['brand:id,name,business_id', 'draft'])
            ->where('planned_for', '>=', now()->subDays(30)->toDateString())
            ->orderBy('planned_for')->orderBy('id')->limit(200)->get();
        $published = ContentPublication::query()->whereIn('draft_id', $entries->pluck('draft_id')->filter()->all())->where('status', 'published')->pluck('draft_id')->all();

        $rows = $entries->map(function (ContentCalendarEntry $e) use ($published) {
            $open = in_array($e->status, ['planned', 'drafted'], true);
            $brief = $open ? $this->calendar->brief($e) : null;

            return [
                'uuid' => $e->uuid,
                'planned_for' => $e->planned_for?->toDateString(),
                'brand' => $e->brand?->name,
                'business_id' => $e->business_id,
                'platform' => $e->platform,
                'occasion' => $e->occasion,
                'product_lines' => array_values((array) $e->product_lines),
                'idea' => $e->idea,
                'status' => in_array($e->draft_id, $published, true) ? 'published' : $e->status,
                'skip_reason' => $e->skip_reason,
                'has_brief' => $this->calendar->latestBrief((int) $e->brand_id) !== null,
                'draft' => $e->draft ? self::draftRow($e->draft) : null,
                'guidance' => $brief === null ? null : [
                    'persona' => $brief->persona,
                    'playbooks' => array_map(fn (array $p) => ['kind' => $p['kind'], 'title' => $p['title'], 'body' => mb_substr($p['body'], 0, 600)], $brief->playbooks),
                    'forbidden' => array_slice($brief->forbiddenPhrases, 0, 40),
                ],
            ];
        });

        $claims = ContentClaimsRule::query()->visibleTo($user)->with('business:id,name')->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('product_line')->orderByDesc('id')->limit(300)->get()
            ->map(fn (ContentClaimsRule $r) => [
                'uuid' => $r->uuid, 'business' => $r->business?->name, 'product_line' => $r->product_line, 'kind' => $r->kind,
                'phrases' => array_values((array) $r->phrases), 'reason' => $r->reason, 'legal_ref' => $r->legal_ref, 'proof_ref' => $r->proof_ref,
                'status' => $r->status, 'retire_reason' => $r->retire_reason, 'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $brands = Brand::query()->orderBy('name')->get(['id', 'business_id', 'name'])
            ->filter(fn (Brand $b) => $this->scope->allows($user, (int) $b->business_id))
            ->map(fn (Brand $b) => ['id' => $b->id, 'business_id' => $b->business_id, 'name' => $b->name])->values();
        $businesses = Business::query()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Business $b) => $this->scope->allows($user, (int) $b->id))
            ->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name])->values();

        return Inertia::render('content/index', [
            'entries' => $rows,
            'claimsRules' => $claims,
            'floor' => array_map(fn (array $r) => ['id' => $r['id'], 'phrases' => $r['phrases'], 'reason' => $r['reason'], 'legal_ref' => $r['legal_ref']], ClaimsFloor::RULES),
            'posture' => [
                'drafting' => $this->features->enabled(ContentDraftService::FLAG),
                'ai_drafting' => $this->features->enabled(ContentCalendar::AI_FLAG),
                'drafter_connected' => app()->bound(CaptionDrafter::class),
                'ai_paused' => $this->pause->isPaused(),
                'publishing' => $this->features->enabled(PublicationService::FLAG),
            ],
            'options' => ['platforms' => ContentCalendar::PLATFORMS, 'occasions' => ContentCalendar::OCCASIONS, 'brands' => $brands, 'businesses' => $businesses],
            'can' => [
                'plan' => $user->can('hq.content.plan'),
                'review' => $user->can('hq.content.review'),
                'claims' => $user->can('hq.content.claims.manage'),
            ],
        ]);
    }

    public function plan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'planned_for' => ['required', 'date'],
            'platform' => ['required', Rule::in(ContentCalendar::PLATFORMS)],
            'occasion' => ['nullable', Rule::in(ContentCalendar::OCCASIONS)],
            'product_lines' => ['required', 'string', 'max:300'],
            'idea' => ['required', 'string', 'max:1000'],
        ]);
        $brand = Brand::query()->find((int) $data['brand_id']);
        $this->inScope($brand, $brand?->business_id);

        return $this->attempt(fn () => $this->calendar->plan($request->user(), $brand, [
            'planned_for' => $data['planned_for'], 'platform' => $data['platform'], 'occasion' => $data['occasion'] ?? null,
            'product_lines' => preg_split('/[\s,]+/', trim($data['product_lines'])) ?: [], 'idea' => $data['idea'],
        ]), 'content-planned');
    }

    public function draft(Request $request, ContentCalendarEntry $entry): RedirectResponse
    {
        $this->inScope($entry, (int) $entry->business_id);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        return $this->attempt(fn () => $this->calendar->draftByPerson($request->user(), $entry, $data['text']), 'content-drafted');
    }

    public function aiDraft(Request $request, ContentCalendarEntry $entry): RedirectResponse
    {
        $this->inScope($entry, (int) $entry->business_id);

        return $this->attempt(fn () => $this->calendar->draftByAi($request->user(), $entry), 'content-drafted');
    }

    public function review(Request $request, ContentCalendarEntry $entry): RedirectResponse
    {
        $this->inScope($entry, (int) $entry->business_id);
        $data = $request->validate(['verdict' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->calendar->review($request->user(), $entry, $data['verdict'], $data['note'] ?? null), 'content-reviewed');
    }

    public function skip(Request $request, ContentCalendarEntry $entry): RedirectResponse
    {
        $this->inScope($entry, (int) $entry->business_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->calendar->skip($request->user(), $entry, $data['reason']), 'content-skipped');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'product_line' => ['required', 'string', 'max:64'],
            'kind' => ['required', Rule::in(['forbidden', 'allowed'])],
            'phrases' => ['required', 'string', 'max:4000'],
            'reason' => ['required', 'string', 'max:2000'],
            'legal_ref' => ['nullable', 'string', 'max:120'],
            'proof_ref' => ['nullable', 'string', 'max:2000'],
        ]);
        $business = Business::query()->find((int) $data['business_id']);
        $this->inScope($business, $business?->id);

        return $this->attempt(fn () => $this->rules->write($request->user(), $business, [
            'product_line' => $data['product_line'], 'kind' => $data['kind'],
            'phrases' => preg_split('/\r\n|\r|\n|;/', $data['phrases']) ?: [], 'reason' => $data['reason'],
            'legal_ref' => $data['legal_ref'] ?? null, 'proof_ref' => $data['proof_ref'] ?? null,
        ]), 'claims-rule-written');
    }

    public function retireRule(Request $request, ContentClaimsRule $rule): RedirectResponse
    {
        $this->inScope($rule, (int) $rule->business_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->rules->retire($request->user(), $rule, $data['reason']), 'claims-rule-retired');
    }

    /** @return array<string, mixed> */
    private static function draftRow(ContentDraft $d): array
    {
        $checks = (array) $d->checks;

        return [
            'uuid' => $d->uuid,
            'text' => $d->text,
            'status' => $d->status,
            'text_provenance' => $d->text_provenance,
            'checks' => [
                'passed' => (bool) ($checks['passed'] ?? false),
                'unsourced' => array_values(array_map(fn ($f) => (string) ($f['figure'] ?? ''), (array) ($checks['numbers']['unsourced'] ?? []))),
                'violations' => array_values(array_map(fn ($v) => ['phrase' => (string) ($v['phrase'] ?? ''), 'reason' => $v['reason'] ?? null], (array) ($checks['claims_policy']['violations'] ?? []))),
                'uncovered' => array_values((array) ($checks['claims_policy']['uncovered_product_lines'] ?? [])),
                'banned' => array_values((array) ($checks['banned_words']['found'] ?? [])),
                'why' => $checks['claims_policy']['why'] ?? null,
            ],
        ];
    }
}
