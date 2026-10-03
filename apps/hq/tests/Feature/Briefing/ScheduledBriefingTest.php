<?php

use App\Briefing\BriefingNarrator;
use App\Models\AiRun;
use App\Models\Briefing;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\ScheduledJobService;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Briefing\FakeNarrator;

/*
 | docs/95 §16 NOW item 4 (stream W2b item 5): the daily owner briefing as a scheduled job, made by
 | `hq:scheduler:ensure`, 09:00 Asia/Dhaka, `briefing.compose`, origin `ui`. The tool is R3, so the job is
 | created waiting for approval (docs/48 L.5) and does not run until a person approves it. With the AI
 | runtime off and the narration flag absent (production today) a run composes the evidence bundle only:
 | no narrator is resolved, no model is reached, and the run succeeds.
 */

const BRIEFING_ENSURE = 'hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --timezone=Asia/Dhaka --created-from=ui';

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    (new RolesAndPermissionsSeeder)->run();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 07:00:00', 'Asia/Dhaka'));
    $this->owner = User::factory()->create(['email' => 'owner@example.test']);
    $this->owner->assignRole('owner');
    FakeNarrator::$calls = 0;
    app()->instance(BriefingNarrator::class, new FakeNarrator(fn () => 'must never be asked'));
});

it('ensures one daily 09:00 Dhaka briefing job from the ui, waiting for approval because the tool is R3, idempotently', function () {
    $this->artisan(BRIEFING_ENSURE.' --owner=owner@example.test')->assertSuccessful();
    expect(ScheduledJob::count())->toBe(0); // a dry run writes nothing

    $this->artisan(BRIEFING_ENSURE.' --owner=owner@example.test --apply')->assertSuccessful();
    $this->artisan(BRIEFING_ENSURE.' --owner=owner@example.test --apply')->assertSuccessful();

    $job = ScheduledJob::sole();
    expect($job->tool_key)->toBe('briefing.compose')
        ->and($job->cron_expression)->toBe('0 9 * * *')
        ->and($job->timezone)->toBe('Asia/Dhaka')
        ->and($job->created_from)->toBe('ui')
        ->and($job->owner_user_id)->toBe($this->owner->id)
        ->and($job->approval_state)->toBe(ApprovalState::Pending)
        // 09:00 Dhaka is 03:00 UTC, today.
        ->and($job->next_run_at->toIso8601String())->toBe('2026-10-03T03:00:00+00:00');

    // Waiting for approval: the tick at the slot runs nothing, and neither does "Run now".
    $this->artisan('hq:scheduler:tick', ['--now' => '2026-10-03T03:00:00+00:00'])->assertSuccessful();
    expect(JobRun::count())->toBe(0)
        ->and(fn () => app(ScheduledJobService::class)->runNow($job->fresh()))->toThrow(LogicException::class);
});

it('refuses an origin the scheduler does not know', function () {
    $this->artisan('hq:scheduler:ensure "x" --tool=noop --cron="0 9 * * *" --created-from=somewhere --apply')->assertFailed();
    expect(ScheduledJob::count())->toBe(0);
});

it('once approved, composes the evidence bundle only with the runtime and narration off: no narrator, no model, a succeeded run', function () {
    $this->artisan(BRIEFING_ENSURE.' --owner=owner@example.test --apply')->assertSuccessful();
    $job = app(ScheduledJobService::class)->approve(ScheduledJob::sole(), $this->owner);

    $this->artisan('hq:scheduler:tick', ['--now' => $job->next_run_at->toIso8601String()])->assertSuccessful();

    $run = JobRun::sole();
    $briefing = Briefing::sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->output['output']['narration_state'])->toBe('off')
        ->and($run->output['output']['briefing_uuid'])->toBe($briefing->uuid)
        ->and($briefing->narration_state)->toBe('off')
        ->and($briefing->narrative)->toBeNull()
        ->and(FakeNarrator::$calls)->toBe(0)
        ->and(AiRun::count())->toBe(0);
});
