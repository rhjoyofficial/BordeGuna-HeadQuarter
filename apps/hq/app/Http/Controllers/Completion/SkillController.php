<?php

namespace App\Http\Controllers\Completion;

use App\Http\Controllers\Controller;
use App\Models\AgentDelegation;
use App\Models\Business;
use App\Models\Skill;
use App\Skills\Delegations;
use App\Skills\SkillCatalogue;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use App\Support\Http\ListPage;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Skills and delegation (docs/10, docs/89 Phase 9, docs/145-c). People propose, approve, activate, suspend
 * and retire skills; the screen shows every delegation an agent asked for and the narrowed authority it
 * resolved to. ⛔ No route starts a delegated run, calls an agent or takes a tool call.
 */
final class SkillController extends Controller
{
    use RefusesPolitely;

    public function __construct(
        private readonly SkillCatalogue $skills,
        private readonly BusinessScope $scope,
        private readonly Features $features,
        private readonly ToolRegistry $tools,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $restricted = $this->scope->isRestricted($user);

        $skillsPage = ListPage::paginate(Skill::query()->when($restricted, fn ($q) => $q->visibleTo($user))->with('business:id,name')
            ->orderByRaw("CASE status WHEN 'proposed' THEN 0 WHEN 'approved' THEN 1 WHEN 'active' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END")
            ->orderBy('key')->orderByDesc('version'), $request);
        $skills = $skillsPage->getCollection()
            ->map(fn (Skill $s) => [
                'uuid' => $s->uuid, 'key' => $s->key, 'version' => $s->version, 'name' => $s->name, 'purpose' => $s->purpose,
                'required_tools' => array_values((array) $s->required_tools), 'business' => $s->business?->name ?? null, 'group_wide' => $s->business_id === null,
                'proposer_type' => $s->proposer_type, 'status' => $s->status, 'status_reason' => $s->status_reason,
                'self_decided' => $s->approved_by !== null && (int) $s->approved_by === (int) $s->created_by,
                // Ruling 15: the viewer cannot approve or activate a skill they wrote.
                'own' => SkillCatalogue::isAuthor((int) $user->getKey(), $s),
            ]);

        $delegationsPage = ListPage::paginate(AgentDelegation::query()->when($restricted, fn ($q) => $q->visibleTo($user))->orderByDesc('id'), $request, 'delegations_page');
        $delegations = $delegationsPage->getCollection()
            ->map(fn (AgentDelegation $d) => [
                'uuid' => $d->uuid, 'from' => $d->from_agent_key, 'to' => $d->to_agent_key, 'status' => $d->status, 'reason' => $d->refusal_reason,
                'tools' => array_values((array) ($d->effective['tools'] ?? [])), 'business_ids' => $d->effective['business_ids'] ?? null,
                'narrowed' => count((array) ($d->effective['narrowed'] ?? [])), 'created_at' => $d->created_at?->toIso8601String(),
            ]);

        $toolKeys = [];
        foreach ($this->tools->keys() as $key) {
            try {
                if (! $this->tools->get($key)->descriptor()->permanentlyHumanOnly) {
                    $toolKeys[] = $key;
                }
            } catch (Throwable) {
            }
        }
        sort($toolKeys);

        return Inertia::render('skills/index', [
            'skills' => $skills,
            'delegations' => $delegations,
            'skillsPagination' => $skillsPage->meta(),
            'delegationsPagination' => $delegationsPage->meta(),
            'delegationOn' => $this->features->enabled(Delegations::FLAG),
            'options' => [
                'tools' => $toolKeys,
                'businesses' => Business::query()->orderBy('name')->get(['id', 'name'])->filter(fn ($b) => $this->scope->allows($user, (int) $b->id))->values()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
                'group_wide' => ! $restricted,
            ],
            'can' => ['propose' => $user->can('hq.skills.propose'), 'activate' => $user->can('hq.skills.activate')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer'],
            'key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:2000'],
            'required_tools' => ['required', 'array', 'min:1', 'max:20'],
            'required_tools.*' => ['string', 'max:64'],
        ]);
        $business = null;
        if (! empty($data['business_id'])) {
            $business = Business::query()->find((int) $data['business_id']);
            $this->inScope($business, $business?->id);
        }

        return $this->attempt(fn () => $this->skills->propose($request->user(), $business, $data), 'skill-proposed');
    }

    public function transition(Request $request, Skill $skill, string $action): RedirectResponse
    {
        if ($skill->business_id === null ? $this->scope->isRestricted($request->user()) : ! $this->scope->allows($request->user(), (int) $skill->business_id)) {
            abort(404);
        }
        $permission = $action === 'suspend' ? 'hq.skills.propose' : 'hq.skills.activate';
        abort_unless($request->user()->can($permission), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => match ($action) {
            'approve' => $this->skills->approve($request->user(), $skill, $data['reason']),
            'activate' => $this->skills->activate($request->user(), $skill, $data['reason']),
            'suspend' => $this->skills->suspend($request->user(), $skill, $data['reason']),
            'retire' => $this->skills->retire($request->user(), $skill, $data['reason']),
        }, "skill-{$action}");
    }
}
