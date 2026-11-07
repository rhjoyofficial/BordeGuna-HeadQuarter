<?php

use App\Agents\RoleBoundary;
use App\Avatar\AvatarConsents;
use App\Avatar\AvatarGenerations;
use App\Avatar\AvatarProvider;
use App\Avatar\FakeAvatarProvider;
use App\Models\Agent;
use App\Models\AgentDelegation;
use App\Models\AgentRun;
use App\Models\AvatarConsent;
use App\Models\AvatarGeneration;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Skill;
use App\Models\ToolCall;
use App\Research\ResearchRefused;
use App\Skills\Delegations;
use App\Skills\SkillCatalogue;
use App\Tools\Avatar\GenerateAvatarTool;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Completion\M4;
use Tests\Support\Execution\Contexts;

/*
 | ChatGPT ruling 31 Oct 2026, items 14, 15 (skills) and 16.
 |  14: an avatar never answers a customer or acts as customer support (structural + behavioural).
 |  15: a skill's author never approves or activates it; a separate eligible reviewer is required, and with
 |      none the skill stays an inactive draft.
 |  16: delegation is record-only; nothing in HQ starts another agent's execution from a delegation.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->lld = Brand::where('code', 'LLD')->sole();
});

/** Source code without comments, so a comment naming a forbidden thing is not mistaken for a call to it. */
function r3Code(string $path): string
{
    return collect(token_get_all((string) file_get_contents($path)))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
}

// ── 15. Skills: independent review ─────────────────────────────────────────────────────────────────

it('never lets a skill\'s author approve or activate it; with no other reviewer it stays an inactive draft', function () {
    $this->actingAsHq($this->owner)->post('/skills', ['key' => 'sole_author', 'name' => 'S', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']])->assertSessionHasNoErrors();
    $skill = Skill::query()->sole();

    // The owner is the only reviewer there is, and still cannot approve or activate what they wrote.
    $this->actingAsHq($this->owner)->post("/skills/{$skill->uuid}/approve", ['reason' => 'mine'])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post("/skills/{$skill->uuid}/activate", ['reason' => 'mine'])->assertSessionHasErrors('form');
    expect($skill->fresh()->status)->toBe('proposed');

    // A separate reviewer approves; the author still may not activate.
    $reviewer = $this->hqUser('operator');
    $this->actingAsHq($reviewer)->post("/skills/{$skill->uuid}/approve", ['reason' => 'read it'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/skills/{$skill->uuid}/activate", ['reason' => 'go'])->assertSessionHasErrors('form');
    expect($skill->fresh()->status)->toBe('approved');
    $this->actingAsHq($reviewer)->post("/skills/{$skill->uuid}/activate", ['reason' => 'go'])->assertSessionHasNoErrors();
    expect($skill->fresh()->status)->toBe('active')->and($skill->fresh()->approved_by)->toBe($reviewer->id);
});

it('refuses to activate a skill whose only approval was its author\'s, and counts the named owner as an author too', function () {
    $reviewer = $this->hqUser('operator');
    $catalogue = app(SkillCatalogue::class);
    $skill = $catalogue->propose($this->owner, null, ['key' => 'legacy_self', 'name' => 'L', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']]);
    // As a pre-ruling row would be: approved by the person who wrote it.
    $skill->forceFill(['status' => 'approved', 'approved_by' => $this->owner->id, 'approved_at' => now()])->save();
    expect(fn () => $catalogue->activate($reviewer, $skill->fresh(), 'go'))->toThrow(ResearchRefused::class, 'independent reviewer');

    // An agent's proposal recorded by a person: the recorder is the author; another skill names the reviewer as owner.
    $agentSkill = $catalogue->propose($this->owner, null, ['key' => 'agent_said', 'name' => 'A', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']], 'agent', 'proposal-1');
    expect(fn () => $catalogue->approve($this->owner, $agentSkill, 'ok'))->toThrow(ResearchRefused::class, 'author');
    $owned = $catalogue->propose($this->owner, null, ['key' => 'owned_by_reviewer', 'name' => 'O', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']]);
    $owned->forceFill(['owner_user_id' => $reviewer->id])->save();
    expect(fn () => $catalogue->approve($reviewer, $owned->fresh(), 'ok'))->toThrow(ResearchRefused::class, 'author');
    expect(Skill::query()->where('status', 'active')->count())->toBe(0);
});

// ── 14. Avatar: never a customer reply ─────────────────────────────────────────────────────────────

it('structurally keeps the avatar out of every customer reply and support path', function () {
    expect(array_intersect(AvatarConsents::USES, AvatarConsents::CUSTOMER_FACING_USES))->toBe([])
        ->and(AvatarConsents::CUSTOMER_FACING_USES)->toContain(RoleBoundary::CUSTOMER_REPLY)
        ->and(AvatarConsents::isCustomerFacing('customer_reply'))->toBeTrue()
        ->and(AvatarConsents::isCustomerFacing('anything_new'))->toBeTrue();

    $enum = app(GenerateAvatarTool::class)->inputSchema()['properties']['use']['enum'];
    expect(array_intersect($enum, AvatarConsents::CUSTOMER_FACING_USES))->toBe([])
        ->and(app(GenerateAvatarTool::class)->descriptor()->roleAction)->not->toBe(RoleBoundary::CUSTOMER_REPLY);

    // No customer-reply tool, conversation or agent code may reach the avatar, and no avatar code declares a customer reply.
    $offenders = [];
    foreach (File::allFiles(app_path()) as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());
        $code = r3Code($file->getPathname());
        $avatar = str_contains($code, 'App\\Avatar\\') || str_contains($code, 'GenerateAvatarTool') || str_contains($code, 'AvatarGeneration');
        $customerSide = str_starts_with($relative, 'Conversation/') || str_starts_with($relative, 'Tools/Conversation/') || str_starts_with($relative, 'Agents/')
            || str_contains($code, "roleAction: 'customer_reply'") || str_contains($code, 'RoleBoundary::CUSTOMER_REPLY');
        if ($avatar && $customerSide && ! str_starts_with($relative, 'Avatar/')) {
            $offenders[] = $relative;
        }
    }
    expect($offenders)->toBe([]);

    $registry = app(ToolRegistry::class);
    foreach ($registry->keys() as $key) {
        $tool = $registry->get($key);
        if ($tool->descriptor()->roleAction === RoleBoundary::CUSTOMER_REPLY) {
            expect(r3Code((new ReflectionClass($tool))->getFileName()))->not->toContain('Avatar');
        }
    }
});

it('refuses an avatar for a customer reply in the screen, the service and the database, whatever the consent, flag or provider', function () {
    $body = ['business_id' => $this->laladia->id, 'person_label' => 'Presenter A', 'kind' => 'synthetic_persona', 'modalities' => ['face'],
        'brand_ids' => [$this->lld->id], 'expires_at' => now()->addMonths(6)->toDateString()];
    foreach (AvatarConsents::CUSTOMER_FACING_USES as $use) {
        // The screen's validation refuses it, and so does the service behind it on its own.
        $this->actingAsHq($this->owner)->post('/avatar/consents', $body + ['permitted_uses' => ['product_promo', $use]])->assertSessionHasErrors();
        expect(fn () => app(AvatarConsents::class)->record($this->owner, $this->laladia, $body + ['permitted_uses' => ['product_promo', $use]]))
            ->toThrow(ResearchRefused::class, 'never answers a customer');
    }
    expect(AvatarConsent::query()->count())->toBe(0);

    $this->actingAsHq($this->owner)->post('/avatar/consents', $body + ['permitted_uses' => ['product_promo']])->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();
    // The database refuses to widen a consent to a customer-facing use even past the code (the guard trigger and the CHECK).
    expect(fn () => DB::transaction(fn () => DB::table('avatar_consents')->insert(['uuid' => (string) Str::uuid(), 'business_id' => $this->laladia->id,
        'person_label' => 'X', 'kind' => 'synthetic_persona', 'modalities' => json_encode(['face']), 'permitted_uses' => json_encode(['customer_reply']),
        'brand_ids' => json_encode([$this->lld->id]), 'expires_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);

    // Every other gate open: AI running, flag on, a provider bound, a valid consent. A customer reply is still refused.
    M4::on('avatar.generation');
    $provider = new FakeAvatarProvider;
    app()->instance(AvatarProvider::class, $provider);
    foreach (['customer_reply', 'customer_support'] as $use) {
        expect(fn () => app(AvatarGenerations::class)->generate($this->owner, $consent->id, $this->lld->id, $use, 'face', 'আপনার অর্ডার পথে আছে।'))
            ->toThrow(ResearchRefused::class, 'never answers a customer');
    }
    expect($provider->requests)->toBe([])
        ->and(AvatarGeneration::query()->where('status', 'refused')->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => AvatarGeneration::query()->where('status', 'refused')->first()->forceFill(['status' => 'requested'])->save()))->toThrow(QueryException::class);
});

// ── 16. Delegation: record-only ─────────────────────────────────────────────────────────────────────

it('has no delegated run starter and no runtime path from a delegation to another agent\'s execution', function () {
    // Nothing named DelegatedRunStarter exists as code, binding or route.
    $declares = [];
    foreach (File::allFiles(app_path()) as $file) {
        if (preg_match('/\b(class|interface|trait)\s+DelegatedRunStarter\b|DelegatedRunStarter::class/', r3Code($file->getPathname())) === 1) {
            $declares[] = $file->getRelativePathname();
        }
    }
    expect($declares)->toBe([])->and(app()->bound('App\\Skills\\DelegatedRunStarter'))->toBeFalse();

    // The delegation service holds nothing that runs an agent, a job, a workflow or a tool.
    $code = r3Code(app_path('Skills/Delegations.php'));
    foreach (['AgentRunner', 'AskHq', 'dispatch', 'Bus::', 'Queue::', 'ShouldQueue', 'Workflow', 'ToolGateway', 'Gateway\\', 'ScheduledJob', 'Artisan::', '->run('] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // Only the delegation service writes delegation records, and only the skills screen (read-only) and the
    // resolver name it; no route, command, job, listener or tool does.
    $users = [];
    foreach (File::allFiles(app_path()) as $file) {
        $code = r3Code($file->getPathname());
        if (preg_match('/\bDelegations\b|\bAgentDelegation\b/', $code) === 1) {
            $users[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }
    sort($users);
    expect($users)->toBe(['Http/Controllers/Completion/SkillController.php', 'Models/AgentDelegation.php', 'Skills/Delegations.php']);
    foreach (Route::getRoutes() as $route) {
        expect((string) $route->getActionName())->not->toContain('Delegation');
    }
});

it('records a resolved delegation and starts nothing: no agent run, no tool call, no job', function () {
    Queue::fake();
    Bus::fake();
    $this->seed(AgentSeeder::class);
    Agent::query()->whereIn('key', ['executive', 'analytics'])->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $reviewer = $this->hqUser('operator');
    $catalogue = app(SkillCatalogue::class);
    $skill = $catalogue->propose($this->owner, null, ['key' => 'snap', 'name' => 'S', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']]);
    $catalogue->approve($reviewer, $skill, 'ok');
    $skill = $catalogue->activate($reviewer, $skill->fresh(), 'go');
    M4::on('agents.delegation');

    $runs = AgentRun::query()->count();
    $calls = ToolCall::query()->count();
    $row = app(Delegations::class)->delegate(Contexts::for(Agent::where('key', 'executive')->sole()), Agent::where('key', 'analytics')->sole(), $skill);

    expect($row->status)->toBe('resolved')->and(AgentDelegation::query()->count())->toBe(1)
        ->and(AgentRun::query()->count())->toBe($runs)->and(ToolCall::query()->count())->toBe($calls);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
});
