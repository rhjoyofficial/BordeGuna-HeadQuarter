<?php

use App\Gateway\ToolGateway;
use App\Models\ScheduledJob;
use App\Scheduling\ScheduledJobService;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

/*
 | M2: the tool namespace moved out of App\Scheduling\Tools.
 |
 | It was there because Phase 0 built tools for the scheduler and nothing else. Tools now serve the
 | scheduler, the console, the gateway's own callers and, from M5, agents, so the old namespace was
 | describing one caller as though it were the owner.
 |
 | The risk in this move is not the classes: it is `config('hq.tools')`, which the LIVE scheduler
 | reads to find them. A stale class reference there is a production outage at 02:00 rather than a
 | failure in CI, which is why these tests exist.
 */

it('registers every configured tool from the new namespace', function () {
    $classes = config('hq.tools');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue("configured tool class [{$class}] does not exist")
            ->and(str_starts_with($class, 'App\\Tools\\'))->toBeTrue("[{$class}] is not in the App\\Tools namespace")
            ->and(app($class))->toBeInstanceOf(Tool::class);
    }
});

it('keeps the production tool keys unchanged, because scheduled_jobs rows point at them', function () {
    // ⚠ A tool key is an API identity, not a display label. Renaming one of these silently breaks
    // every scheduled_jobs row that references it, and the failure appears on a nightly run.
    $live = [
        'noop', 'test.fail', 'erp.poll_overview', 'erp.poll_low_stock',
        'commerce.reconcile_facts', 'commerce.check_fact_integrity',
        // M3 added the approval demonstration tool. It is R4 and writes only to HQ audit log.
        'demo.request_approval',
        // M5 added the read set the two agents answer from. Every one is R1 or R2 and read-only.
        // ⚠ These keys are now as load-bearing as the others: `agents.allowed_tools` references them
        // by name, so renaming one silently empties an agent's tool list rather than failing.
        'facts.query_orders', 'facts.snapshot_freshness', 'erp.read_snapshot', 'erp.run_report',
        'commerce.list_orders', 'hq.list_approvals', 'hq.list_jobs', 'hq.recent_events',
        // Phase 3 stream 3 (docs/72 §B): the support agent's thread read and its one proposal-only write.
        'conversation.read_thread', 'messaging.send_reply',
        // M7 (docs/80 C.1): composes and stores the Executive Briefing's evidence bundle. No job runs it yet.
        'briefing.compose',
        // Phase 4 read side (docs/81 C.5): five live ERP reads, R2, read-only, held by no agent.
        'erp.lookup_product', 'erp.lookup_sku', 'erp.read_price', 'erp.read_availability', 'erp.check_order_eligibility',
        // Phase 4 order write (docs/83 C.3, docs/88 A point 3): R4, approval-required, held by no agent, no store admitted.
        'commerce.take_order',
        // Phase 3 channel adapters (docs/88 A point 2): the WhatsApp reply, its own tool, not agent-executable, no provider registered.
        'messaging.send_whatsapp_reply',
        // Phase 4 order completion (docs/88 A point 3): two R2 reads held by no agent, and the R4
        // compensating cancel under the same empty store list.
        'commerce.quote_order', 'commerce.get_order_status', 'commerce.cancel_sales_order',
        // docs/114 (S7 follow-up, ruling (k)): the two-person lift of a do-not-contact. R3 write of HQ's own
        // ledger, parks on every call, permanently human-only. No scheduled job names it.
        'consent.lift_restriction',
        // S3 follow-up (docs/115 §4, ruling Q11): the operator-asserted attribution, R3, permanently human-only, held by no agent.
        'outcomes.attribute',
    ];

    expect(app(ToolRegistry::class)->keys())->toEqualCanonicalizing($live);
});

it('resolves every tool key a scheduled job can reference, including the live production set', function () {
    $registry = app(ToolRegistry::class);

    // The five keys production actually schedules today. Creating a job for each proves the whole
    // path resolves after the namespace move: the service validates the key against the registry,
    // and the registry builds the class.
    //
    // ⚠ Written as an explicit list rather than as a loop over existing rows, because a fresh test
    // database has no scheduled jobs, and a loop over an empty set asserts nothing while looking
    // like it asserts everything.
    $live = ['noop', 'erp.poll_overview', 'erp.poll_low_stock', 'commerce.reconcile_facts', 'commerce.check_fact_integrity'];

    foreach ($live as $key) {
        expect($registry->has($key))->toBeTrue("[{$key}] is scheduled in production but is not registered");

        $job = app(ScheduledJobService::class)->create([
            'name' => "resolves {$key}", 'kind' => 'cron', 'cron_expression' => '0 4 * * *', 'tool_key' => $key,
        ], null);
        expect($job->tool_key)->toBe($key);
    }

    // And anything already in the table resolves too, which covers a key this list forgot.
    foreach (ScheduledJob::query()->pluck('tool_key')->unique() as $key) {
        expect($registry->has($key))->toBeTrue("a scheduled job references [{$key}], which is not registered");
    }
});

it('leaves no reference to the old namespace anywhere in the application or its configuration', function () {
    $stale = [];
    foreach (['app', 'config', 'database', 'routes'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            // The docblock in ToolRegistration names the interface in prose, which is fine; a `use`
            // or a fully-qualified reference is not.
            if (preg_match('/App\\\\Scheduling\\\\Tools\\\\/', $body)) {
                $stale[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    expect($stale)->toBe([], 'stale namespace references: '.implode(', ', $stale));
});

it('gives every registered tool a usable descriptor after the move', function () {
    foreach (app(ToolGateway::class)->descriptors() as $key => $descriptor) {
        expect($descriptor->key)->toBe($key)
            ->and($descriptor->permission)->toStartWith('hq.')
            ->and($descriptor->auditAction)->toStartWith('tool.');
    }
});
