<?php

use App\Gateway\Approval\ApprovalStatus;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Credentials\CredentialKinds;
use App\Tools\Hq\ListApprovalsTool;
use App\Tools\Hq\ListJobsTool;
use App\Tools\ToolContext;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 | docs/136: every screen that lists or shows business-owned rows narrows them to the businesses a
 | restricted person may see (rulings docs/120 A4, docs/131 §1). An id of another business answers 404,
 | a row with no business is hidden from a restricted person (ADR-035 §4, fail closed), and an
 | unrestricted owner still sees everything.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->mine = Business::orderBy('id')->firstOrFail();
    $this->theirs = Business::where('id', '!=', $this->mine->id)->orderBy('id')->firstOrFail();
    $this->owner = $this->hqUser('owner');

    // An operator holds every permission, so a refusal below is the business scope and never the role.
    $this->restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $this->restricted->id, 'business_id' => $this->mine->id]);
});

function scopeApproval(?int $businessId, string $reason): Approval
{
    return Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => 'demo.request_approval', 'tool_version' => '1.0.0',
        'payload' => ['note' => $reason], 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r3',
        'action_class' => 'approval_required', 'reason' => $reason, 'status' => 'pending', 'business_id' => $businessId,
        'sla_due_at' => now()->subHour(), 'expires_at' => now()->addDay()]);
}

function scopeJob(?int $businessId, string $name): ScheduledJob
{
    return ScheduledJob::create(['name' => $name, 'kind' => 'cron', 'cron_expression' => '0 4 * * *', 'tool_key' => 'hq.list_jobs',
        'state' => 'active', 'approval_state' => 'approved', 'business_id' => $businessId, 'last_status' => 'failed']);
}

function scopeFailedRun(ScheduledJob $job): JobRun
{
    return JobRun::create(['scheduled_job_id' => $job->id, 'scheduled_for' => now()->subHour(), 'trigger' => 'tick', 'status' => 'failed',
        'finished_at' => now()->subHour(), 'error' => "error of {$job->name}"]);
}

it('lists only the approvals of the businesses a restricted person may see, with counts to match', function () {
    scopeApproval($this->mine->id, 'mine');
    scopeApproval($this->theirs->id, 'theirs');
    scopeApproval(null, 'group');

    $this->actingAsHq($this->restricted)->get('/approvals')->assertOk()
        ->assertInertia(fn ($page) => $page->has('approvals', 1)->where('approvals.0.reason', 'mine')
            ->where('counts.pending', 1)->where('counts.overdue', 1)
            ->where('nav.badges.approvals', 1));

    $this->actingAsHq($this->owner)->get('/approvals')->assertOk()
        ->assertInertia(fn ($page) => $page->has('approvals', 3)->where('counts.pending', 3));
});

it('answers 404 on another business\'s approval, and on a group-level one, for show and every decision', function () {
    $mine = scopeApproval($this->mine->id, 'mine');
    $other = User::factory()->create();
    $other->assignRole('operator');

    foreach ([scopeApproval($this->theirs->id, 'theirs'), scopeApproval(null, 'group')] as $hidden) {
        $this->actingAsHq($this->restricted)->get("/approvals/{$hidden->id}")->assertNotFound();
        $this->actingAsHq($this->restricted)->post("/approvals/{$hidden->id}/approve")->assertNotFound();
        $this->actingAsHq($this->restricted)->post("/approvals/{$hidden->id}/reject", ['reason' => 'no'])->assertNotFound();
        $this->actingAsHq($this->restricted)->post("/approvals/{$hidden->id}/cancel")->assertNotFound();
        $this->actingAsHq($this->restricted)->post("/approvals/{$hidden->id}/delegate", ['user_id' => $other->id])->assertNotFound();
        expect($hidden->fresh()->status)->toBe(ApprovalStatus::Pending)->and($hidden->fresh()->delegated_to_user_id)->toBeNull();

        $this->actingAsHq($this->owner)->get("/approvals/{$hidden->id}")->assertOk();
    }

    $this->actingAsHq($this->restricted)->get("/approvals/{$mine->id}")->assertOk();
});

it('refuses to delegate an approval to a person who may not see its business', function () {
    $approval = scopeApproval($this->theirs->id, 'theirs');
    $outsider = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $outsider->id, 'business_id' => $this->mine->id]);

    $this->actingAsHq($this->owner)->post("/approvals/{$approval->id}/delegate", ['user_id' => $outsider->id])
        ->assertSessionHasErrors('user_id');
    expect($approval->fresh()->delegated_to_user_id)->toBeNull();
});

it('lists only the scheduled jobs of the businesses a restricted person may see, and scopes the failure counts', function () {
    scopeFailedRun(scopeJob($this->mine->id, 'mine'));
    scopeFailedRun(scopeJob($this->theirs->id, 'theirs'));
    scopeFailedRun(scopeJob(null, 'group'));

    $this->actingAsHq($this->restricted)->get('/scheduler')->assertOk()
        ->assertInertia(fn ($page) => $page->has('jobs.data', 1)->where('jobs.data.0.name', 'mine')
            ->where('nav.badges.scheduler', 1));

    $this->actingAsHq($this->owner)->get('/scheduler')->assertOk()
        ->assertInertia(fn ($page) => $page->has('jobs.data', 3)->where('nav.badges.scheduler', 3));
});

it('answers 404 on another business\'s job, and on a group-level one, for show, run-now, pause and the rest', function () {
    $mine = scopeJob($this->mine->id, 'mine');

    foreach ([scopeJob($this->theirs->id, 'theirs'), scopeJob(null, 'group')] as $hidden) {
        $run = scopeFailedRun($hidden);
        $runs = JobRun::count();

        $this->actingAsHq($this->restricted)->get("/scheduler/{$hidden->id}")->assertNotFound();
        foreach (['approve', 'reject', 'pause', 'resume', 'cancel', 'run-now'] as $action) {
            $this->actingAsHq($this->restricted)->post("/scheduler/{$hidden->id}/{$action}")->assertNotFound();
        }
        $this->actingAsHq($this->restricted)->post("/scheduler/runs/{$run->id}/retry")->assertNotFound();

        expect($hidden->fresh()->state->value)->toBe('active')
            ->and(JobRun::count())->toBe($runs)
            ->and($run->fresh()->attempt)->toBe(0);

        $this->actingAsHq($this->owner)->get("/scheduler/{$hidden->id}")->assertOk();
    }

    $this->actingAsHq($this->restricted)->get("/scheduler/{$mine->id}")->assertOk();
});

it('lets a restricted person create a job only for one of their businesses', function () {
    $job = ['name' => 'nightly', 'kind' => 'cron', 'tool_key' => 'hq.list_jobs', 'cron_expression' => '0 3 * * *'];

    $this->actingAsHq($this->restricted)->post('/scheduler', $job + ['business_id' => $this->theirs->id])->assertSessionHasErrors('business_id');
    $this->actingAsHq($this->restricted)->post('/scheduler', $job)->assertSessionHasErrors('business_id');
    $theirBrand = Brand::where('business_id', $this->theirs->id)->first();
    if ($theirBrand !== null) {
        $this->actingAsHq($this->restricted)->post('/scheduler', $job + ['business_id' => $this->mine->id, 'brand_id' => $theirBrand->id])
            ->assertSessionHasErrors('brand_id');
    }
    expect(ScheduledJob::count())->toBe(0);

    $this->actingAsHq($this->restricted)->post('/scheduler', $job + ['business_id' => $this->mine->id])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/scheduler', $job)->assertSessionHasNoErrors();
    expect(ScheduledJob::count())->toBe(2);
});

it('narrows the dashboard counts to the person\'s businesses and keeps the audit log off it for a restricted person', function () {
    scopeFailedRun(scopeJob($this->mine->id, 'mine'));
    scopeFailedRun(scopeJob($this->theirs->id, 'theirs'));
    scopeFailedRun(scopeJob(null, 'group'));
    AuditLog::create(['actor_type' => 'system', 'action' => 'test.row', 'occurred_at' => now()]);
    Alert::create(['rule' => 'facts.snapshot_stale', 'fingerprint' => 'x', 'severity' => 'warning', 'subject' => 'stale for another business',
        'body' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->actingAsHq($this->restricted)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.jobs_active', 1)->where('counts.runs_failed_24h', 1)
            ->where('counts.businesses', 1)->where('counts.audit_24h', 0)->where('recentAudit', [])
            ->where('attention.3.key', 'jobs_failed')->where('attention.3.count', 1)
            ->missing('nav.badges.alerts'));

    $this->actingAsHq($this->owner)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.jobs_active', 3)->where('counts.runs_failed_24h', 3)
            ->where('counts.businesses', Business::count())->where('attention.3.count', 3)
            ->has('recentAudit', fn ($rows) => $rows->etc())->where('nav.badges.alerts', 1));
});

it('refuses the group-wide screens (audit log, alerts, platform) to a restricted person and keeps them for the owner', function () {
    foreach (['/audit', '/observe/alerts', '/observe/platform'] as $url) {
        $this->actingAsHq($this->restricted)->get($url)->assertForbidden();
        $this->actingAsHq($this->owner)->get($url)->assertOk();
    }

    $finance = $this->hqUser('finance_viewer');
    UserBusinessScope::create(['user_id' => $finance->id, 'business_id' => $this->mine->id]);
    $this->actingAsHq($finance)->get('/audit')->assertForbidden();
    $this->actingAsHq($this->hqUser('finance_viewer'))->get('/audit')->assertOk();
});

it('shows a restricted person only their businesses on the businesses screen', function () {
    $this->actingAsHq($this->restricted)->get('/businesses')->assertOk()
        ->assertInertia(fn ($page) => $page->has('businesses', 1)->where('businesses.0.id', $this->mine->id));
    $this->actingAsHq($this->owner)->get('/businesses')->assertOk()
        ->assertInertia(fn ($page) => $page->has('businesses', Business::count()));
});

it('shows, stores and revokes credentials only inside the person\'s businesses', function () {
    $make = fn (?int $businessId, string $ref) => IntegrationCredential::create(['provider' => 'meta', 'account_ref' => $ref, 'business_id' => $businessId,
        'credential_type' => 'token', 'payload' => ['value' => 'x'], 'status' => 'active']);
    $mine = $make($this->mine->id, 'mine');
    $theirs = $make($this->theirs->id, 'theirs');
    $group = $make(null, 'group');

    $this->actingAsHq($this->restricted)->get('/credentials')->assertOk()
        ->assertInertia(fn ($page) => $page->has('credentials', 1)->where('credentials.0.id', $mine->id)
            ->has('businesses', 1)
            ->where('readiness', fn ($rows) => collect($rows)->every(fn ($r) => $r['target_kind'] !== 'hq')));
    $this->actingAsHq($this->owner)->get('/credentials')->assertOk()
        ->assertInertia(fn ($page) => $page->has('credentials', 3));

    foreach ([$theirs, $group] as $hidden) {
        $this->actingAsHq($this->restricted)->post("/credentials/{$hidden->id}/revoke")->assertNotFound();
        expect($hidden->fresh()->status)->toBe('active');
    }

    $kind = collect(CredentialKinds::keys())->first();
    $this->actingAsHq($this->restricted)->post('/credentials', ['provider' => $kind, 'credential_type' => 'token', 'secret' => 's3cret',
        'business_id' => $this->theirs->id])->assertSessionHasErrors('business_id');
    $this->actingAsHq($this->restricted)->post('/credentials', ['provider' => $kind, 'credential_type' => 'token', 'secret' => 's3cret'])
        ->assertSessionHasErrors('business_id');
});

it('counts and lists recent tool calls on the tools screen only inside the person\'s businesses', function () {
    foreach ([$this->mine->id, $this->theirs->id, null] as $businessId) {
        ToolCall::create(['tool_key' => 'hq.list_jobs', 'status' => 'succeeded', 'input' => [], 'output' => [], 'business_id' => $businessId,
            'started_at' => now(), 'finished_at' => now()]);
    }
    $row = fn ($page) => collect($page->toArray()['props']['tools'])->firstWhere('key', 'hq.list_jobs');

    $this->actingAsHq($this->restricted)->get('/tools')->assertOk()
        ->assertInertia(function ($page) use ($row) {
            expect($row($page)['calls_24h'])->toBe(1)->and($row($page)['recent'])->toHaveCount(1);
        });
    $this->actingAsHq($this->owner)->get('/tools')->assertOk()
        ->assertInertia(function ($page) use ($row) {
            expect($row($page)['calls_24h'])->toBe(3)->and($row($page)['recent'])->toHaveCount(3);
        });
});

it('narrows the counts and the failures the list tools return, not only their lists', function () {
    scopeFailedRun(scopeJob($this->mine->id, 'mine'));
    scopeFailedRun(scopeJob($this->theirs->id, 'theirs'));
    scopeApproval($this->mine->id, 'mine');
    scopeApproval($this->theirs->id, 'theirs');
    scopeApproval(null, 'group');

    $restricted = new ToolContext(idempotencyKey: 'scope', correlationId: 'scope', permittedBusinessIds: [$this->mine->id]);
    $groupWide = new ToolContext(idempotencyKey: 'scope', correlationId: 'scope');

    $jobs = app(ListJobsTool::class)->execute([], $restricted)->output;
    expect($jobs['failing_count'])->toBe(1)
        ->and(collect($jobs['recent_failures'])->pluck('job')->all())->toBe(['mine']);
    expect(app(ListJobsTool::class)->execute([], $groupWide)->output['failing_count'])->toBe(2);

    expect(app(ListApprovalsTool::class)->execute([], $restricted)->output['pending_count'])->toBe(1)
        ->and(app(ListApprovalsTool::class)->execute([], $groupWide)->output['pending_count'])->toBe(3);
});

it('sends the recent audit rows only to a group-wide person who holds hq.audit.view (decided 17 Oct)', function () {
    AuditLog::create(['actor_type' => 'system', 'action' => 'test.row', 'occurred_at' => now()]);

    // support_agent opens the dashboard but not the audit log: no rows, unrestricted or not.
    $this->actingAsHq($this->hqUser('support_agent'))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('recentAudit', []));

    $this->actingAsHq($this->hqUser('finance_viewer'))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->has('recentAudit', 1));
    $this->actingAsHq($this->owner)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->has('recentAudit', 1));
});
