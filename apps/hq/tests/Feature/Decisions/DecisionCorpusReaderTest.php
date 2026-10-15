<?php

use App\Decisions\DecisionCorpusReader;
use App\Decisions\DecisionCorpusWindow;
use App\Decisions\EditClassifier;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use App\Models\UserBusinessScope;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/111 (stream S5, ruling docs/103 §7 and §11): the decision-corpus reader. Read-only, business-scoped,
 | deterministic categories from the stored deltas, counts and references only (never the text), one existing
 | permission, and rendering or printing it writes nothing.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

/**
 * An inbox decision on a support reply proposal, in the LEGACY shape (`send`/`discard` stored as the decision, before
 * docs/120 item 20). The model now refuses those words on create, so a historical row is written past its events.
 */
function corpusReply(Business $business, string $from, ?string $to, string $decision = 'send', array $extra = []): DecisionRecord
{
    $thread = Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'channel' => 'messenger', 'status' => 'open', 'opened_at' => now()]);
    $edited = $to !== null && $to !== $from;

    return DecisionRecord::withoutEvents(fn () => DecisionRecord::create($extra + [
        'agent_key' => 'support', 'user_id' => test()->owner->id, 'context_type' => 'conversation', 'context_id' => $thread->uuid,
        'proposal' => ['run_id' => 1, 'text' => $from, 'why' => 'secret-why-text'],
        'final' => ['decision' => $decision, 'text' => $to, 'edited' => $edited],
        'delta' => $edited ? ['from' => $from, 'to' => $to] : null,
        'decision' => $decision, 'reason' => 'secret-reason-text', 'outcome' => ['status' => $decision === 'send' ? 'ok' : 'discarded'],
    ]));
}

/** An approval decision (ApprovalService::recordDecision shape). */
function corpusApproval(Business $business, string $tool, array $proposal, ?array $final, string $decision, array $extra = []): DecisionRecord
{
    $approval = Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => $tool, 'tool_version' => '1.0.0', 'payload' => $proposal,
        'payload_hash' => str_repeat('b', 64), 'risk_tier' => 'r3', 'action_class' => 'approval_required', 'status' => 'pending',
        'business_id' => $business->id, 'agent_key' => 'executive', 'expires_at' => now()->addDay()]);
    $delta = null;
    if ($final !== null && $decision === 'edited') {
        $delta = [];
        foreach (array_unique([...array_keys($proposal), ...array_keys($final)]) as $key) {
            if (($proposal[$key] ?? null) !== ($final[$key] ?? null)) {
                $delta[$key] = ['from' => $proposal[$key] ?? null, 'to' => $final[$key] ?? null];
            }
        }
    }

    return DecisionRecord::create($extra + [
        'agent_key' => 'executive', 'user_id' => test()->owner->id, 'context_type' => 'approval', 'context_id' => (string) $approval->id,
        'proposal' => $proposal, 'final' => $final, 'delta' => $delta, 'decision' => $decision, 'reason' => 'secret-approval-reason',
        'outcome' => ['tool_key' => $tool, 'risk_tier' => 'r3'],
    ]);
}

/** A shadow review (AgentController::review shape): verdict and note, no delta. */
function corpusReview(Business $business, string $verdict): DecisionRecord
{
    $run = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(), 'trigger' => 'console',
        'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered', 'business_id' => $business->id, 'answer' => 'secret-answer-text']);

    return DecisionRecord::create([
        'agent_key' => 'executive', 'user_id' => test()->owner->id, 'context_type' => 'agent_run', 'context_id' => $run->uuid,
        'proposal' => ['answer' => 'secret-answer-text', 'proposals' => []], 'final' => ['verdict' => $verdict, 'note' => 'secret-note-text'],
        'decision' => $verdict, 'reason' => 'secret-note-text', 'outcome' => ['status' => 'answered'],
    ]);
}

function corpusRead(?int $businessId = null, string $window = 'all'): array
{
    return app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from($window), test()->owner, $businessId);
}

function corpusProps($response): array
{
    return $response->viewData('page')['props'];
}

it('is a GET behind auth, both 2FA gates and the agents permission that already exists, and adds no write route', function () {
    $route = Route::getRoutes()->getByName('observe.decision-corpus');

    expect($route)->not->toBeNull()
        ->and('/'.$route->uri())->toBe('/observe/decision-corpus')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.agents.view')
        ->and(RolesAndPermissionsSeeder::PERMISSIONS)->toContain('hq.agents.view')
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'observe/decision-corpus') && array_diff($r->methods(), ['GET', 'HEAD']) !== [])->count())->toBe(0);
});

it('opens for the roles that may see the agents and for no other', function () {
    $this->get('/observe/decision-corpus')->assertRedirect();

    foreach (['owner' => true, 'operator' => true, 'brand_manager' => true, 'finance_viewer' => false, 'support_agent' => false] as $role => $allowed) {
        $response = $this->actingAsHq($this->hqUser($role))->get('/observe/decision-corpus');
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
});

it('derives every edit category from the stored change by a fixed rule, and identically on a second read', function () {
    $cases = [
        'language_or_script' => corpusReply($this->laladia, 'Your order will reach you tomorrow.', 'আপনার অর্ডার আগামীকাল পৌঁছে যাবে।'),
        'register' => corpusReply($this->laladia, 'তোমার অর্ডার আগামীকাল পৌঁছে যাবে।', 'আপনার অর্ডার আগামীকাল পৌঁছে যাবে।'),
        'refusal_added' => corpusReply($this->laladia, 'আপনার অর্ডার আজ রাতে পৌঁছে যাবে।', 'দুঃখিত, আপনার অর্ডার আজ রাতে পৌঁছানো যাবে না।'),
        'refusal_removed' => corpusReply($this->laladia, 'Sorry, we cannot deliver to Khulna.', 'We deliver to Khulna within three days.'),
        'fact_corrected' => corpusReply($this->laladia, 'The 500g jar is 650 taka with free delivery.', 'The 500g jar is 690 taka with free delivery.'),
        'shortened' => corpusReply($this->laladia, 'Thank you for your message. We have received your order and it will be dispatched tomorrow morning by our courier partner.', 'Received, dispatching tomorrow morning.'),
        'expanded' => corpusReply($this->laladia, 'Dispatching tomorrow.', 'Thank you for your order. We are dispatching it tomorrow morning and the courier will call you before delivery.'),
        'wording' => corpusReply($this->laladia, 'Your order will be dispatched tomorrow morning.', 'Your order will be sent tomorrow morning.'),
        'rewritten' => corpusReply($this->laladia, 'Your order will be dispatched tomorrow morning.', 'Please share the delivery address once more.'),
    ];
    $arguments = [
        'scope_narrowed' => corpusApproval($this->laladia, 'commerce.cancel_sales_order', ['quantity' => 5, 'lines' => [1, 2, 3]], ['quantity' => 2, 'lines' => [1, 2, 3]], 'edited'),
        'scope_widened' => corpusApproval($this->laladia, 'commerce.cancel_sales_order', ['quantity' => 2], ['quantity' => 4], 'edited'),
        'target_changed' => corpusApproval($this->laladia, 'commerce.take_order', ['customer_phone' => '01711000001', 'amount' => 100], ['customer_phone' => '01711000002', 'amount' => 100], 'edited'),
        'fact_corrected' => corpusApproval($this->laladia, 'commerce.take_order', ['customer_phone' => '01711000001', 'district' => 'Dhaka'], ['customer_phone' => '01711000001', 'district' => 'Gazipur'], 'edited'),
    ];
    $review = corpusReview($this->laladia, 'edited');

    $classifier = app(EditClassifier::class);
    foreach ($cases as $category => $record) {
        expect($classifier->classifyText($record->delta['from'], $record->delta['to']))->toContain($category);
    }
    expect($classifier->classifyText($cases['wording']->delta['from'], $cases['wording']->delta['to']))->toBe(['wording'])
        ->and($classifier->classifyText($cases['rewritten']->delta['from'], $cases['rewritten']->delta['to']))->toBe(['rewritten'])
        ->and($classifier->classifyText('same', 'same'))->toBe([]);
    foreach ($arguments as $category => $record) {
        expect($classifier->classifyArguments($record->delta)['labels'])->toContain($category);
    }
    expect($classifier->classifyArguments($arguments['scope_narrowed']->delta))->toBe(['labels' => ['scope_narrowed'], 'keys' => ['quantity']]);

    $first = corpusRead();
    $second = corpusRead();
    expect($second)->toBe($first);

    $byCategory = collect($first['categories'])->keyBy('category');
    foreach ([...array_keys($cases), ...array_keys($arguments), 'verdict_only'] as $category) {
        expect($byCategory->has($category))->toBeTrue("category {$category} missing from the report");
    }
    expect($byCategory['verdict_only']['examples'][0]['context_id'])->toBe($review->context_id)
        ->and($byCategory['register']['examples'][0]['id'])->toBe($cases['register']->id)
        ->and($byCategory['fact_corrected']['edits'])->toBe(2)
        ->and(collect($first['fields'])->map(fn ($f) => [$f['tool'], $f['key'], $f['edits']])->all())
        ->toBe([['commerce.cancel_sales_order', 'quantity', 2], ['commerce.take_order', 'customer_phone', 1], ['commerce.take_order', 'district', 1]])
        ->and($first['gaps']['shadow_reviews_edited_without_text'])->toBe(1);
});

it('groups by agent, tool, kind and business on one verdict axis, and names a correction that recurs with its references', function () {
    $a = corpusReply($this->laladia, 'তোমার অর্ডার আসছে।', 'আপনার অর্ডার আসছে।');
    $b = corpusReply($this->laladia, 'তোমার পার্সেল আজ যাবে।', 'আপনার পার্সেল আজ যাবে।');
    corpusReply($this->bionic, 'Sent as proposed.', 'Sent as proposed.');
    corpusReply($this->bionic, 'Dropped.', null, 'discard');
    corpusApproval($this->laladia, 'commerce.take_order', ['amount' => 100], ['amount' => 100], 'approved');
    corpusApproval($this->laladia, 'commerce.take_order', ['amount' => 100], null, 'rejected');
    corpusReview($this->laladia, 'approved');

    $report = corpusRead();

    expect($report['totals'])->toMatchArray(['records' => 7, 'kept' => 3, 'edited' => 2, 'rejected' => 2])
        ->and($report['totals']['by_stored_decision'])->toBe(['approved' => 2, 'discard' => 1, 'edited' => 0, 'rejected' => 1, 'send' => 3])
        ->and(collect($report['by_agent'])->map(fn ($r) => [$r['agent_key'], $r['role_key'], $r['records'], $r['edited']])->all())
        ->toBe([['support', 'support', 4, 2], ['executive', 'executive', 3, 0]])
        ->and(collect($report['by_tool'])->map(fn ($r) => [$r['tool'], $r['records']])->all())
        ->toBe([['messaging.send_reply', 4], ['commerce.take_order', 2], ['(answer)', 1]])
        ->and(collect($report['by_kind'])->pluck('records', 'kind')->all())->toBe(['reply_text' => 4, 'tool_arguments' => 2, 'shadow_answer' => 1])
        ->and(collect($report['by_business'])->map(fn ($r) => [$r['slug'], $r['records'], $r['rejected']])->all())
        ->toBe([['laladia', 5, 1], ['bionic', 2, 1]]);

    expect($report['patterns'])->toHaveCount(1)
        ->and($report['patterns'][0])->toMatchArray(['agent_key' => 'support', 'tool' => 'messaging.send_reply', 'category' => 'register', 'count' => 2, 'businesses' => ['laladia']])
        ->and(collect($report['patterns'][0]['examples'])->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($report['patterns'][0]['examples'][0])->toMatchArray(['context_type' => 'conversation', 'context_id' => $a->context_id, 'href' => '/inbox/'.$a->context_id]);
});

it('never ships proposal, final, delta or reason text, and renders a reference link only where the viewer may open its page', function () {
    corpusReply($this->laladia, 'secret-from-text তোমার', 'secret-to-text আপনার');
    corpusReply($this->laladia, 'secret-from-text তোমার অর্ডার', 'secret-to-text আপনার অর্ডার');
    corpusApproval($this->laladia, 'commerce.take_order', ['amount' => 100, 'note' => 'secret-arg-from'], ['amount' => 90, 'note' => 'secret-arg-to'], 'edited');
    corpusReview($this->laladia, 'edited');

    $json = json_encode(corpusProps($this->actingAsHq($this->owner)->get('/observe/decision-corpus?window=all')->assertOk()));
    foreach (['secret-from-text', 'secret-to-text', 'secret-why', 'secret-reason', 'secret-arg-from', 'secret-arg-to', 'secret-approval-reason', 'secret-answer', 'secret-note'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    $this->artisan('hq:decisions:report', ['--window' => 'all'])->assertSuccessful()
        ->expectsOutputToContain('records 4: kept 0, edited 4, rejected 0')
        ->doesntExpectOutputToContain('secret-');

    // brand_manager: hq.agents.view and hq.approvals.view, not hq.inbox.view.
    $props = corpusProps($this->actingAsHq($this->hqUser('brand_manager'))->get('/observe/decision-corpus?window=all')->assertOk());
    $hrefs = collect($props['categories'])->flatMap(fn ($c) => $c['examples'])->keyBy('context_type')->map(fn ($e) => $e['href']);
    expect($props['can'])->toBe(['approvals' => true, 'agents' => true, 'inbox' => false])
        ->and($hrefs['conversation'])->toBeNull()
        ->and($hrefs['approval'])->toStartWith('/approvals/')
        ->and($hrefs['agent_run'])->toStartWith('/agents/runs/');
});

it('enforces business scope from the context row: a restricted person sees only their businesses\' records and nothing unscoped', function () {
    corpusReply($this->laladia, 'তোমার অর্ডার', 'আপনার অর্ডার');
    corpusReply($this->bionic, 'তোমার অর্ডার', 'আপনার অর্ডার');
    corpusApproval($this->bionic, 'commerce.take_order', ['amount' => 100], ['amount' => 90], 'edited');
    // A record whose context row is gone: no business can be read for it.
    corpusReply($this->bionic, 'x', 'y', 'send', ['context_id' => (string) Str::uuid()]);

    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->laladia->id, 'access' => 'full']);

    $mine = corpusProps($this->actingAsHq($scoped)->get('/observe/decision-corpus?window=all')->assertOk());
    expect($mine['scope']['restricted'])->toBeTrue()
        ->and(collect($mine['scope']['businesses'])->pluck('slug')->all())->toBe(['laladia'])
        ->and($mine['scope']['unscoped_records'])->toBe(0)
        ->and($mine['totals']['records'])->toBe(1)
        ->and(collect($mine['by_business'])->pluck('slug')->all())->toBe(['laladia'])
        ->and(json_encode($mine))->not->toContain('bionic')->not->toContain('commerce.take_order');

    // Narrowing to a business outside the restriction yields nothing, not another business's rows.
    $outside = corpusProps($this->actingAsHq($scoped)->get('/observe/decision-corpus?window=all&business='.$this->bionic->id)->assertOk());
    expect($outside['totals']['records'])->toBe(0)->and($outside['by_business'])->toBe([]);

    // Group-wide sees all four, the orphan under unscoped; narrowing to Bionic gives Bionic's two with a business.
    $all = corpusRead();
    expect($all['totals']['records'])->toBe(4)
        ->and($all['scope']['unscoped_records'])->toBe(1)
        ->and($all['gaps']['contexts_missing'])->toBe(1)
        ->and(collect($all['by_business'])->map(fn ($r) => [$r['slug'], $r['records']])->all())->toBe([['bionic', 2], ['laladia', 1], [null, 1]]);
    expect(corpusRead($this->bionic->id)['totals']['records'])->toBe(2);
});

it('respects the window and prints the same report from the console', function () {
    $old = corpusReply($this->laladia, 'তোমার অর্ডার', 'আপনার অর্ডার');
    DB::table('decision_records')->where('id', $old->id)->update(['created_at' => now()->subDays(100)]);
    corpusReply($this->laladia, 'তোমার পার্সেল', 'আপনার পার্সেল');

    expect(corpusRead(null, '90d')['totals']['records'])->toBe(1)
        ->and(corpusRead(null, 'all')['totals']['records'])->toBe(2)
        ->and(DecisionCorpusWindow::from('nonsense')->key)->toBe('90d');

    Artisan::call('hq:decisions:report', ['--window' => 'all', '--json' => true]);
    $printed = json_decode(Artisan::output(), true);
    $expected = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all'));
    // The console has no clock pinned to the page's; everything but the window bounds must match.
    unset($printed['window'], $expected['window']);
    expect($printed)->toEqual(json_decode(json_encode($expected), true));

    $this->artisan('hq:decisions:report', ['--window' => 'weekly'])->assertFailed();
});

it('writes nothing when it is read or printed', function () {
    corpusReply($this->laladia, 'তোমার অর্ডার', 'আপনার অর্ডার');
    corpusApproval($this->laladia, 'commerce.take_order', ['amount' => 100], ['amount' => 90], 'edited');
    corpusReview($this->laladia, 'rejected');
    $brandManager = $this->hqUser('brand_manager');

    $tables = ['decision_records', 'approvals', 'agent_runs', 'conversations', 'conversation_messages', 'audit_logs', 'events', 'tool_calls', 'ai_runs', 'agents', 'businesses', 'brands'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.DB::table($t)->max('id')])->all()
        + ['decision_rows' => DB::table('decision_records')->orderBy('id')->get()->toJson(),
            'approval_rows' => DB::table('approvals')->orderBy('id')->get()->toJson(),
            'run_rows' => DB::table('agent_runs')->orderBy('id')->get()->toJson(),
            'conversation_rows' => DB::table('conversations')->orderBy('id')->get()->toJson()];

    $before = $snapshot();
    $this->actingAsHq($this->owner)->get('/observe/decision-corpus?window=all')->assertOk();
    $this->actingAsHq($brandManager)->get('/observe/decision-corpus?window=all&business='.$this->laladia->id)->assertOk();
    $this->artisan('hq:decisions:report', ['--window' => 'all'])->assertSuccessful();

    expect($snapshot())->toBe($before);
});

it('counts a record the database stamped by rounding up past the window end', function () {
    // CI run 38078297032: `decision_records.created_at` is timestamptz(0), so Postgres rounds the write time to
    // the nearest second. A record written at 10:00:05.6 is stored as 10:00:06, later than a window that ends
    // at 10:00:05.6 by PHP's clock; with the old bound it fell outside the window.
    $record = corpusReply($this->laladia, 'তোমার অর্ডার', 'আপনার অর্ডার');
    DB::table('decision_records')->where('id', $record->id)->update(['created_at' => '2026-10-11 10:00:05.600000+00']);
    expect(DB::table('decision_records')->where('id', $record->id)->value('created_at'))->toContain('10:00:06');

    $report = app(DecisionCorpusReader::class)->read(DecisionCorpusWindow::from('all', CarbonImmutable::parse('2026-10-11 10:00:05.600000', 'UTC')));

    expect($report['totals']['records'])->toBe(1);
});
