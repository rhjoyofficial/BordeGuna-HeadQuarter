<?php

namespace App\Http\Controllers\Playbooks;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Business;
use App\Models\PlaybookVersion;
use App\Models\PlaybookVersionEvent;
use App\Models\User;
use App\Playbooks\PlaybookContent;
use App\Playbooks\PlaybookKind;
use App\Playbooks\Playbooks;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The playbook screens (docs/112 §7, S6): what is active per business, brand and kind, what is waiting,
 * the full history of a key, and the acts: draft, edit, submit, approve or send back, activate, retire,
 * withdraw. Every act goes through `Playbooks`, as the acting person, behind 2FA, a permission and the
 * business scope; the screen never edits a row itself.
 *
 * ⛔ Nothing here is read by an agent. The runtime reader is `PlaybookLoader`, which reads active rows
 * only; this controller is the people's side of the same table.
 */
final class PlaybookController extends Controller
{
    public function __construct(
        private readonly Playbooks $playbooks,
        private readonly BusinessScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $businesses = $this->scope->restrict(Business::query()->with(['brands' => fn ($q) => $q->orderBy('name')]), $user, 'id')
            ->where('is_active', true)->orderBy('name')->get();

        $versions = PlaybookVersion::query()->with('brand:id,code,name')
            ->whereIn('business_id', $businesses->pluck('id'))
            ->whereIn('status', [PlaybookVersion::ACTIVE, PlaybookVersion::DRAFT, PlaybookVersion::IN_REVIEW, PlaybookVersion::REVIEWED])
            ->orderByDesc('version')
            ->get()
            ->groupBy(fn (PlaybookVersion $v) => $v->business_id.'/'.($v->brand_id ?? 0).'/'.$v->kind->value);

        $people = $this->people($versions->flatten());

        $rows = $businesses->map(function (Business $business) use ($versions, $people) {
            $scopes = [['brand_id' => null, 'brand' => null, 'code' => null], ...$business->brands->map(fn (Brand $b) => ['brand_id' => $b->id, 'brand' => $b->name, 'code' => $b->code])->all()];
            $table = [];
            foreach ($scopes as $scope) {
                foreach (PlaybookKind::loadOrder() as $kind) {
                    $group = $versions->get($business->id.'/'.($scope['brand_id'] ?? 0).'/'.$kind->value, collect());
                    $active = $group->first(fn (PlaybookVersion $v) => $v->status === PlaybookVersion::ACTIVE);
                    $pending = $group->first(fn (PlaybookVersion $v) => $v->status !== PlaybookVersion::ACTIVE);
                    if ($active === null && $pending === null) {
                        continue;
                    }
                    $table[] = $scope + [
                        'kind' => $kind->value,
                        'kind_label' => $kind->label(),
                        'active' => $active === null ? null : $this->summary($active, $people),
                        'pending' => $pending === null ? null : $this->summary($pending, $people),
                    ];
                }
            }

            return [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'brands' => $business->brands->map(fn (Brand $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values(),
                'rows' => $table,
            ];
        });

        return Inertia::render('playbooks/index', [
            'businesses' => $rows,
            'kinds' => $this->kinds(),
            'limits' => ['max_chars' => PlaybookContent::maxCharsPerVersion(), 'max_chars_per_run' => PlaybookContent::maxCharsPerRun()],
            'can' => $this->can($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', Rule::exists('businesses', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'kind' => ['required', Rule::in(PlaybookKind::values())],
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:'.PlaybookContent::maxCharsPerVersion()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_unless($this->scope->allows($request->user(), (int) $data['business_id']), 403);
        $business = Business::findOrFail((int) $data['business_id']);
        $brand = isset($data['brand_id']) ? Brand::findOrFail((int) $data['brand_id']) : null;

        try {
            $version = $this->playbooks->draft($request->user(), $business, $brand, PlaybookKind::from($data['kind']),
                ['title' => $data['title'], 'summary' => $data['summary'] ?? null, 'body' => $data['body']], $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()])->withInput();
        }

        return redirect()->route('playbooks.show', $version)->with('status', "playbook-drafted:{$version->uuid}");
    }

    public function show(Request $request, PlaybookVersion $version): Response
    {
        $this->authorizeBusiness($request, $version);
        $version->load(['business:id,name,slug', 'brand:id,code,name']);

        $siblings = $version->siblings()->with('brand:id,code,name')->get();
        $events = PlaybookVersionEvent::query()->where('playbook_version_id', $version->id)->orderByDesc('id')->get();
        $people = $this->people(collect([$version, ...$siblings]));

        return Inertia::render('playbooks/show', [
            'version' => $this->summary($version, $people) + [
                'body' => $version->body,
                'summary' => $version->summary,
                'warnings' => PlaybookContent::warnings($version->body),
                'business' => ['id' => $version->business_id, 'name' => $version->business?->name, 'slug' => $version->business?->slug],
                'supersedes_version' => $version->supersedes_id === null ? null : PlaybookVersion::query()->whereKey($version->supersedes_id)->value('version'),
                'review_note' => $version->review_note,
                'activation_reason' => $version->activation_reason,
                'retirement_reason' => $version->retirement_reason,
                'withdrawal_reason' => $version->withdrawal_reason,
                'submitted_by' => $people[$version->submitted_by] ?? null,
                'submitted_at' => $version->submitted_at?->toIso8601String(),
                'reviewed_by' => $people[$version->reviewed_by] ?? null,
                'reviewed_at' => $version->reviewed_at?->toIso8601String(),
                'retired_by' => $people[$version->retired_by] ?? null,
                'retired_at' => $version->retired_at?->toIso8601String(),
                'withdrawn_by' => $people[$version->withdrawn_by] ?? null,
                'withdrawn_at' => $version->withdrawn_at?->toIso8601String(),
            ],
            'events' => $events->map(fn (PlaybookVersionEvent $e) => [
                'id' => $e->id,
                'event' => $e->event,
                'actor' => $e->actor_label,
                'reason' => $e->reason,
                'content_digest' => $e->content_digest,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])->values(),
            'history' => $siblings->map(fn (PlaybookVersion $s) => $this->summary($s, $people))->values(),
            'kinds' => $this->kinds(),
            'limits' => ['max_chars' => PlaybookContent::maxCharsPerVersion()],
            'can' => $this->can($request->user()),
        ]);
    }

    public function update(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:'.PlaybookContent::maxCharsPerVersion()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->act(fn () => $this->playbooks->edit($request->user(), $version, $data, $data['reason'] ?? null), 'playbook-edited');
    }

    public function submit(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->act(fn () => $this->playbooks->submit($request->user(), $version, $data['reason'] ?? null), 'playbook-submitted');
    }

    public function review(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'send_back'])],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        return $this->act(fn () => $this->playbooks->review($request->user(), $version, $data['decision'] === 'approve', $data['note']), 'playbook-reviewed');
    }

    public function activate(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->act(fn () => $this->playbooks->activate($request->user(), $version, $data['reason']), 'playbook-activated');
    }

    public function retire(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->act(fn () => $this->playbooks->retire($request->user(), $version, $data['reason']), 'playbook-retired');
    }

    public function withdraw(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $this->authorizeBusiness($request, $version);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->act(fn () => $this->playbooks->withdraw($request->user(), $version, $data['reason']), 'playbook-withdrawn');
    }

    /** @param  callable(): PlaybookVersion  $act */
    private function act(callable $act, string $status): RedirectResponse
    {
        try {
            $version = $act();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['act' => $e->getMessage()])->withInput();
        }

        return redirect()->route('playbooks.show', $version)->with('status', "{$status}:{$version->uuid}");
    }

    private function authorizeBusiness(Request $request, PlaybookVersion $version): void
    {
        abort_unless($this->scope->allows($request->user(), $version->business_id), 403);
    }

    /** @param  array<int, string>  $people */
    private function summary(PlaybookVersion $v, array $people): array
    {
        return [
            'uuid' => $v->uuid,
            'brand_id' => $v->brand_id,
            'brand' => $v->brand?->name,
            'kind' => $v->kind->value,
            'kind_label' => $v->kind->label(),
            'version' => $v->version,
            'status' => $v->status,
            'title' => $v->title,
            'content_digest' => $v->content_digest,
            'chars' => mb_strlen($v->body),
            'drafted_by' => $people[$v->drafted_by] ?? null,
            'drafted_at' => $v->drafted_at?->toIso8601String(),
            'activated_by' => $people[$v->activated_by] ?? null,
            'activated_at' => $v->activated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, string> user id => email, for every person named on the given versions */
    private function people(iterable $versions): array
    {
        $ids = [];
        foreach ($versions as $v) {
            foreach (['drafted_by', 'submitted_by', 'reviewed_by', 'activated_by', 'retired_by', 'withdrawn_by'] as $column) {
                if ($v->{$column} !== null) {
                    $ids[] = (int) $v->{$column};
                }
            }
        }

        return $ids === [] ? [] : User::query()->whereIn('id', array_unique($ids))->pluck('email', 'id')->all();
    }

    private function kinds(): array
    {
        return array_map(fn (PlaybookKind $k) => ['value' => $k->value, 'label' => $k->label(), 'hint' => $k->hint()], PlaybookKind::loadOrder());
    }

    private function can(User $user): array
    {
        return [
            'draft' => $user->can(Playbooks::DRAFT),
            'review' => $user->can(Playbooks::REVIEW),
            'activate' => $user->can(Playbooks::ACTIVATE),
        ];
    }
}
