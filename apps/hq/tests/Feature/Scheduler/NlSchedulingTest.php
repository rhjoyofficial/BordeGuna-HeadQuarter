<?php

use App\Ai\ModelTier;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\UserBusinessScope;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Nl\JobSpecDraft;
use App\Scheduling\Nl\JobSpecSchema;
use App\Scheduling\Nl\JobSpecValidator;
use App\Scheduling\Nl\NlScheduling;
use App\Scheduling\ScheduledJobService;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/78 B.3, docs/48 L: scheduling in words, built inert behind `scheduler.nl`. The model proposes;
 | the validator decides; a person confirms; the job waits for approval when its tool is R3 or higher.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    aiModel('fixture', 'fixture-low', ModelTier::Low);
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00', 'Asia/Dhaka'));
    $this->manager = $this->hqUser('owner');
});

function nlFlag(bool $on = true): void
{
    FeatureFlag::updateOrCreate(['key' => NlScheduling::FLAG, 'environment' => '*'], ['enabled' => $on, 'rollout' => null]);
    app(Features::class)->forget();
}

function nlSpec(array $overrides = []): array
{
    return $overrides + [
        'outcome' => 'job', 'question' => '', 'name' => 'Morning fact check', 'kind' => 'cron',
        'tool_key' => 'commerce.check_fact_integrity', 'cron_expression' => '0 8 * * *', 'run_at_local' => '',
        'interval_minutes' => 0, 'event_type' => '', 'timezone' => 'Asia/Dhaka', 'business' => '', 'store' => 'laladia.com',
        'payload_json' => '{}',
    ];
}

function nlCheck(array $overrides = [], $user = null)
{
    return app(JobSpecValidator::class)->validate(JobSpecDraft::fromArray(nlSpec($overrides)), $user ?? test()->manager);
}

it('keeps the model schema inside the subset both the provider and the validator understand', function () {
    $schema = JobSpecSchema::schema();

    expect(array_keys($schema))->each->toBeIn(JobSpecSchema::PERMITTED_ROOT_KEYWORDS)
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']));

    foreach ($schema['properties'] as $name => $rules) {
        expect(array_keys($rules))->each->toBeIn(JobSpecSchema::PERMITTED_PROPERTY_KEYWORDS)
            ->and($rules['type'])->toBeIn(['string', 'integer']);
    }

    expect(app(ArgumentValidator::class)->validate(nlSpec(), $schema)->valid)->toBeTrue();
});

it('adds no path from a model to a tool: the parser only proposes, and only the scheduler runs jobs', function () {
    foreach (glob(app_path('Scheduling/Nl/*.php')) as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toContain('ToolGateway')->not->toContain('AgentRunner')->not->toContain('->execute(')
            ->and(str_contains($source, 'ModelRuntime'))->toBe(basename($file) === 'NlJobParser.php');
    }
});

it('turns a clear sentence into a job it can stand behind, with the store resolved and the first run in Dhaka', function () {
    $result = nlCheck();

    expect($result->ok)->toBeTrue()
        ->and($result->attributes)->toMatchArray([
            'name' => 'Morning fact check',
            'kind' => 'cron',
            'tool_key' => 'commerce.check_fact_integrity',
            'cron_expression' => '0 8 * * *',
            'timezone' => 'Asia/Dhaka',
            'payload' => ['store_slug' => 'laladia-com'],
            'approval_state' => 'approved',
        ])
        ->and($result->preview['first_run_utc'])->toBe('2026-09-18T02:00:00+00:00')
        ->and($result->preview['store'])->toBe('laladia.com')
        ->and($result->preview['needs_approval'])->toBeFalse();

    $once = nlCheck(['kind' => 'once', 'cron_expression' => '', 'run_at_local' => '2026-09-18 09:30']);
    expect($once->ok)->toBeTrue()
        ->and($once->attributes['run_at']->toIso8601String())->toBe('2026-09-18T03:30:00+00:00');

    $interval = nlCheck(['kind' => 'interval', 'cron_expression' => '', 'interval_minutes' => 90]);
    expect($interval->attributes['interval_seconds'])->toBe(5400);

    // docs/95 §16 NOW item 4: an event job is real now (the drain fires it), so a sentence may make one.
    // It has no clock slot: its first run is whenever the event next happens.
    $event = nlCheck(['kind' => 'event', 'cron_expression' => '', 'event_type' => ' order.created ']);
    expect($event->ok)->toBeTrue()
        ->and($event->attributes['event_type'])->toBe('order.created')
        ->and($event->preview['schedule'])->toBe('whenever order.created happens')
        ->and($event->preview['first_run_utc'])->toBeNull();
});

it('asks instead of guessing', function (array $overrides, string $expected) {
    $result = nlCheck($overrides);

    expect($result->ok)->toBeFalse()
        ->and($result->attributes)->toBe([])
        ->and(implode(' | ', $result->questions))->toContain($expected);
})->with([
    'the model asked' => [['outcome' => 'question', 'question' => 'Which store?'], 'Which store?'],
    'an invented tool' => [['tool_key' => 'commerce.refund_order'], 'HQ has no tool called "commerce.refund_order"'],
    'an unknown business' => [['business' => 'Nowhere Ltd'], 'HQ has no business called "Nowhere Ltd"'],
    'an unknown store' => [['store' => 'shop.example'], 'HQ has no store called "shop.example"'],
    'a store the tool cannot take' => [['tool_key' => 'facts.snapshot_freshness'], 'does not take a store'],
    'an unreadable schedule' => [['cron_expression' => 'every day'], 'could not read that as a repeating schedule'],
    'a day some months lack' => [['cron_expression' => '0 8 31 * *'], 'Some months have no day 31'],
    'a time already passed' => [['kind' => 'once', 'run_at_local' => '2026-09-17 09:00'], 'has already passed'],
    'an unreadable time' => [['kind' => 'once', 'run_at_local' => '17/09/2026 9am'], 'could not read when the job should run'],
    'too often' => [['kind' => 'interval', 'interval_minutes' => 0], 'at most once a minute'],
    'an event trigger with no event named' => [['kind' => 'event', 'event_type' => 'when an order comes'], 'Which event should start the job'],
    'no kind' => [['kind' => 'none'], 'When should the job run'],
    'an unknown timezone' => [['timezone' => 'Mars/Olympus'], 'is not a timezone HQ knows'],
    'wrong arguments' => [['payload_json' => '{"hours": "many"}'], 'The arguments for'],
    'arguments that are not an object' => [['payload_json' => '[1, 2]'], 'could not be read'],
    'arguments that are not JSON' => [['payload_json' => '{hours: 3'], 'could not be read'],
]);

it('keeps a restricted person inside their own businesses', function () {
    $user = $this->hqUser('brand_manager');
    UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', 'bionic')->value('id')]);

    expect(implode(' ', nlCheck([], $user)->questions))->toContain('You cannot schedule work for laladia.com')
        ->and(nlCheck(['store' => 'bionic.garden'], $user)->ok)->toBeTrue()
        ->and(implode(' ', nlCheck(['tool_key' => 'facts.snapshot_freshness', 'store' => '', 'business' => 'LaLaDia'], $user)->questions))
        ->toContain('You cannot schedule work for LaLaDia');
});

it('computes the first run in the job\'s own zone across a daylight-saving change', function () {
    $spec = ['tool_key' => 'facts.snapshot_freshness', 'store' => '', 'timezone' => 'America/New_York', 'cron_expression' => '0 9 * * *'];

    $before = app(JobSpecValidator::class)->validate(JobSpecDraft::fromArray(nlSpec($spec)), $this->manager, CarbonImmutable::parse('2026-10-30 20:00', 'UTC'));
    $after = app(JobSpecValidator::class)->validate(JobSpecDraft::fromArray(nlSpec($spec)), $this->manager, CarbonImmutable::parse('2026-10-31 20:00', 'UTC'));

    // 09:00 in New York is 13:00 UTC in daylight time and 14:00 UTC once it ends on 1 November.
    expect($before->preview['first_run_utc'])->toBe('2026-10-31T13:00:00+00:00')
        ->and($after->preview['first_run_utc'])->toBe('2026-11-01T14:00:00+00:00');
});

it('makes a job that runs an R3 tool wait for a person, and nothing can run it before', function () {
    $result = nlCheck(['tool_key' => 'commerce.reconcile_facts', 'name' => 'Nightly reconcile']);
    expect($result->ok)->toBeTrue()
        ->and($result->attributes['approval_state'])->toBe('pending')
        ->and($result->preview['needs_approval'])->toBeTrue();

    $service = app(ScheduledJobService::class);
    $job = $service->create($result->attributes + ['nl_source_text' => 'x', 'reason' => 'test'], $this->manager, 'nl');
    expect($job->approval_state)->toBe(ApprovalState::Pending)
        ->and(AuditLog::where('action', 'scheduled_job.created')->sole()->reason)->toBe('test');

    expect(fn () => $service->runNow($job, $this->manager))->toThrow(LogicException::class, 'not approved');
    $this->artisan('hq:scheduler:tick', ['--now' => '2026-09-19T00:00:00Z'])->assertSuccessful();
    expect(JobRun::count())->toBe(0);

    $this->actingAsHq($this->manager)->post("/scheduler/{$job->id}/approve")->assertRedirect()->assertSessionHas('status', 'job-approved');
    $job->refresh();
    expect($job->approval_state)->toBe(ApprovalState::Approved)
        ->and($job->next_run_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'scheduled_job.approved')->sole()->actor_id)->toBe($this->manager->id);

    $other = $service->create($result->attributes, $this->manager, 'nl');
    $this->actingAsHq($this->manager)->post("/scheduler/{$other->id}/reject")->assertRedirect();
    $other->refresh();
    expect($other->approval_state)->toBe(ApprovalState::Rejected)
        ->and($other->state)->toBe(JobState::Cancelled)
        ->and($other->next_run_at)->toBeNull();

    expect(fn () => $service->approve($other, $this->manager))->toThrow(LogicException::class);
});

it('does not exist while its flag is off, and asks nothing while the AI runtime is off', function () {
    $this->actingAsHq($this->manager)->get('/scheduler/nl')->assertNotFound();
    $this->actingAsHq($this->manager)->post('/scheduler/nl/preview', ['text' => 'every day'])->assertNotFound();

    nlFlag();
    $this->actingAsHq($this->manager)->get('/scheduler/nl')->assertOk()
        ->assertInertia(fn ($page) => $page->component('scheduler/nl')->where('runtime_on', false));

    $this->actingAsHq($this->manager)->post('/scheduler/nl/preview', ['text' => 'every morning at 8 check the facts'])
        ->assertRedirect('/scheduler/nl');

    $this->actingAsHq($this->manager)->get('/scheduler/nl')
        ->assertInertia(fn ($page) => $page->where('result.token', null)
            ->where('result.questions.0', fn ($q) => str_contains($q, 'AI runtime is switched off')));

    expect(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(ScheduledJob::count())->toBe(0)
        ->and(AuditLog::where('action', 'scheduler.nl_refused')->count())->toBe(1);

    // Scheduling is a manager's act: the roles without hq.jobs.manage never see the page.
    foreach (['support_agent', 'finance_viewer'] as $role) {
        $this->actingAsHq($this->hqUser($role))->get('/scheduler/nl')->assertForbidden();
    }
});

it('creates exactly the previewed job from a Bangla sentence, once, for the person who saw it', function () {
    nlFlag();
    enableAiRuntime();
    $sentence = 'প্রতিদিন সকাল ৮টায় laladia.com এর অর্ডার ফ্যাক্ট চেক করো';
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode(nlSpec()))]);

    $this->actingAsHq($this->manager)->post('/scheduler/nl/preview', ['text' => $sentence])->assertRedirect('/scheduler/nl');

    $token = null;
    $this->actingAsHq($this->manager)->get('/scheduler/nl')->assertOk()
        ->assertInertia(function ($page) use (&$token) {
            $page->where('result.questions', [])
                ->where('result.preview.tool.key', 'commerce.check_fact_integrity')
                ->where('result.preview.first_run_utc', '2026-09-18T02:00:00+00:00')
                ->where('text', 'প্রতিদিন সকাল ৮টায় laladia.com এর অর্ডার ফ্যাক্ট চেক করো');
            $token = $page->toArray()['props']['result']['token'];
        });
    expect($token)->toBeString();

    // The prompt names HQ's tools and businesses, asks the low tier for the strict schema, and nothing more.
    $request = FixtureProvider::requestsTo('fixture')[0];
    expect($request->system)->toContain('commerce.check_fact_integrity')->toContain('LaLaDia (laladia)')->toContain('laladia.com (laladia-com)')
        ->and($request->jsonSchema)->toBe(JobSpecSchema::schema())
        ->and($request->model->model_id)->toBe('fixture-low')
        ->and($request->messages)->toBe([['role' => 'user', 'content' => $sentence]]);

    // Somebody else cannot use the token, and trying does not spend it.
    $this->actingAsHq($this->hqUser('operator'))->post('/scheduler/nl/confirm', ['token' => $token])->assertSessionHasErrors('token');
    expect(ScheduledJob::count())->toBe(0);

    $this->actingAsHq($this->manager)->post('/scheduler/nl/confirm', ['token' => $token])->assertRedirect();
    $job = ScheduledJob::sole();

    expect($job->created_from)->toBe('nl')
        ->and($job->nl_source_text)->toBe($sentence)
        ->and($job->owner_user_id)->toBe($this->manager->id)
        ->and($job->tool_key)->toBe('commerce.check_fact_integrity')
        ->and($job->payload)->toBe(['store_slug' => 'laladia-com'])
        ->and($job->approval_state)->toBe(ApprovalState::Approved)
        ->and($job->next_run_at->toIso8601String())->toBe('2026-09-18T02:00:00+00:00')
        ->and(AuditLog::where('action', 'scheduler.nl_confirmed')->count())->toBe(1)
        ->and(AuditLog::where('action', 'scheduler.nl_previewed')->count())->toBe(1);

    $this->actingAsHq($this->manager)->post('/scheduler/nl/confirm', ['token' => $token])->assertSessionHasErrors('token');
    $this->actingAsHq($this->manager)->post('/scheduler/nl/confirm', ['token' => (string) Str::uuid()])->assertSessionHasErrors('token');
    expect(ScheduledJob::count())->toBe(1);
});

it('shows the question for an ambiguous sentence and creates nothing', function () {
    nlFlag();
    enableAiRuntime();
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode(nlSpec([
        'outcome' => 'question', 'question' => 'Which store should HQ check?', 'kind' => 'none', 'tool_key' => '', 'cron_expression' => '', 'store' => '',
    ])))]);

    $this->actingAsHq($this->manager)->post('/scheduler/nl/preview', ['text' => 'check the shop every month'])->assertRedirect();
    $this->actingAsHq($this->manager)->get('/scheduler/nl')
        ->assertInertia(fn ($page) => $page->where('result.token', null)->where('result.questions', ['Which store should HQ check?']));

    expect(ScheduledJob::count())->toBe(0);
});

it('refuses a restricted person a job that names no business, and makes no group-level job (decided 17 Oct)', function () {
    nlFlag();
    enableAiRuntime();
    $user = $this->hqUser('brand_manager');
    UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', 'bionic')->value('id')]);

    // The store is theirs, but the job names no business, so it would be a group-level row they could not see.
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode(nlSpec(['store' => 'bionic.garden'])))]);
    $this->actingAsHq($user)->post('/scheduler/nl/preview', ['text' => 'every morning at 8 check the facts'])->assertRedirect('/scheduler/nl');
    $this->actingAsHq($user)->get('/scheduler/nl')->assertOk()
        ->assertInertia(fn ($page) => $page->where('result.token', null)
            ->where('result.questions.0', fn (string $q) => str_contains($q, 'HQ will not make this job: it names no business')
                && str_contains($q, 'your access is limited to Bionic'))
            ->where('businesses', fn ($b) => collect($b)->pluck('name')->all() === [Business::where('slug', 'bionic')->value('name')]));
    expect(ScheduledJob::count())->toBe(0)
        ->and(AuditLog::where('action', 'scheduler.nl_refused')->where('after->reason', 'group_level_for_restricted')->count())->toBe(1);

    // Naming one of their businesses makes the job, for that business.
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode(nlSpec(['store' => 'bionic.garden', 'business' => 'bionic'])))]);
    $this->actingAsHq($user)->post('/scheduler/nl/preview', ['text' => 'every morning at 8 check the bionic facts'])->assertRedirect();
    $token = null;
    $this->actingAsHq($user)->get('/scheduler/nl')->assertInertia(function ($page) use (&$token) {
        $token = $page->toArray()['props']['result']['token'];
    });
    $this->actingAsHq($user)->post('/scheduler/nl/confirm', ['token' => $token])->assertRedirect();
    expect(ScheduledJob::sole()->business_id)->toBe(Business::where('slug', 'bionic')->value('id'));

    // A preview made before a restriction is checked again on confirm.
    $later = $this->hqUser('owner');
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode(nlSpec()))]);
    $this->actingAsHq($later)->post('/scheduler/nl/preview', ['text' => 'every morning at 8 check the facts'])->assertRedirect();
    $this->actingAsHq($later)->get('/scheduler/nl')->assertInertia(function ($page) use (&$token) {
        $token = $page->toArray()['props']['result']['token'];
    });
    UserBusinessScope::create(['user_id' => $later->id, 'business_id' => Business::where('slug', 'bionic')->value('id')]);
    $this->actingAsHq($later)->post('/scheduler/nl/confirm', ['token' => $token])->assertSessionHasErrors('token');
    expect(ScheduledJob::count())->toBe(1);
});
