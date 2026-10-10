<?php

use App\Tools\Tool;
use App\Tools\ToolRegistry;

/*
 | docs/101 §11 "effectful tool governance: KEEP", §12 Q5, docs/103 §3 (S1), docs/105.
 |
 | STRUCTURAL: no effectful tool can execute without passing the Tool Gateway. These tests read the source, so they
 | fail on a new call site before anybody has to remember to write a behavioural test for it:
 |
 |   1. Only `ToolGateway` invokes `execute()`, `reconcile()` or `preview()` on a tool, with NO exception: the
 |      scheduler's direct path, the one exception docs/105 allowed (tools with no effect), was retired with the
 |      `scheduler.gateway` flag by docs/116 (ruling 110 §2f). Every other call of a method by those names in app/ is
 |      on a receiver that is not a tool (the gateway itself, the workflow engine, ...), and a receiver not on that
 |      list fails the suite, so a new one is a conscious, reviewed addition.
 |   2. Nothing outside app/Tools constructs or resolves a concrete tool; tools are reached through the registry.
 |   3. The services behind the effectful tools are called from their tool and nowhere else, so the effect cannot be
 |      reached around the tool (which is how `hq:facts:reconcile` used to reach `commerce.reconcile_facts`'s).
 |   4. The registered effectful tools are exactly the nine reviewed in docs/105 §1.2 plus the two added deliberately
 |      afterwards: `consent.lift_restriction` (docs/114, row 9) and `outcomes.attribute` (docs/115, row 10).
 |      Registering another effectful tool fails here until docs/105's coverage table (and this list) is updated in
 |      the same reviewed change.
 */

/** PHP source with comments and docblocks removed, so prose that names a method is never read as a call. */
function gcCode(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/** @return array<string, string> relative path (from app/) => code without comments */
function gcAppFiles(): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            $files[$relative] = gcCode($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

it('lets only the gateway invoke a tool: the scheduler is a gateway caller with no direct path (docs/116)', function () {
    // Every receiver of ->execute( / ->reconcile( / ->preview( in app/, by file. Anything else fails.
    $allowed = [
        'Gateway/ToolGateway.php' => ['$tool->execute', '$tool->reconcile', '$tool->preview', '$this->preview'],
        // Gateway callers. The scheduler among them, and nothing more (docs/116).
        'Scheduling/Jobs/RunScheduledJob.php' => ['$gateway->execute'],
        'Agents/AgentRunner.php' => ['$this->gateway->execute'],
        'Gateway/Approval/ApprovalExecutor.php' => ['$this->gateway->execute'],
        'Http/Controllers/Inbox/InboxController.php' => ['$this->gateway->execute'],
        // docs/114 §3: the consent screen asks the gateway to park the lift of a do-not-contact for a second person.
        'Http/Controllers/Identity/CustomerConsentController.php' => ['$this->gateway->execute'],
        'Workflow/WorkflowRunner.php' => ['$this->gateway->execute'],
        'Console/Commands/FactsReconcile.php' => ['$gateway->execute'],
        // Not tools: the workflow engine's reconcile, the publication service's own method, two previews of
        // what a person is about to do (an execution context, a parsed schedule).
        'Scheduling/Commands/SchedulerTick.php' => ['$workflows->reconcile'],
        'Content/Publishing/PublicationService.php' => ['$this->reconcile'],
        'Ask/AskHq.php' => ['$this->contexts->preview'],
        'Http/Controllers/Scheduling/NlScheduleController.php' => ['$this->nl->preview'],
    ];

    $found = [];
    foreach (gcAppFiles() as $path => $code) {
        preg_match_all('/(\$[A-Za-z_][\w]*(?:->[A-Za-z_]\w*)*)->(execute|reconcile|preview)\s*\(/', $code, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $found[$path][] = $match[1].'->'.$match[2];
        }
        // A tool's method named as a callable is a call too.
        expect(preg_match('/\[\s*\$\w+\s*,\s*[\'"](execute|reconcile|preview)[\'"]\s*\]/', $code))->toBe(0, "{$path} names a tool method as a callable");
        // A chained call on whatever the registry returns is a tool call outside the gateway.
        expect(preg_match('/->get\([^)]*\)\s*->(execute|reconcile)\s*\(/', $code))->toBe(0, "{$path} calls a tool fetched from the registry");
    }

    foreach ($found as $path => $receivers) {
        expect(array_key_exists($path, $allowed))->toBeTrue("{$path} calls ".implode(', ', array_unique($receivers)).': not a reviewed call site');
        foreach (array_unique($receivers) as $receiver) {
            expect(in_array($receiver, $allowed[$path], true))->toBeTrue("{$path}: {$receiver}( is not a reviewed call site");
        }
    }

    // docs/116: the scheduler holds no direct path, no effect test to choose one, no flag read and no tool of its own.
    $scheduler = gcCode(app_path('Scheduling/Jobs/RunScheduledJob.php'));
    expect($scheduler)->not->toContain('executeDirectly')
        ->and($scheduler)->not->toContain('mustUseGateway')
        ->and($scheduler)->not->toContain('$tool')
        ->and($scheduler)->not->toContain('ToolRegistry')
        ->and($scheduler)->not->toContain('Features')
        ->and($scheduler)->not->toContain('scheduler_gateway_flag')
        ->and($scheduler)->not->toContain('scheduler.gateway')
        ->and(preg_match_all('/->execute\s*\(/', $scheduler))->toBe(1)
        ->and($scheduler)->toContain('$gateway->execute(ToolRequest::forScheduledJob(');

    // And nothing in app/ or config/ reads the retired control.
    foreach (gcAppFiles() as $path => $code) {
        expect(str_contains($code, 'scheduler.gateway') || str_contains($code, 'scheduler_gateway_flag'))->toBeFalse("{$path} reads the retired scheduler.gateway control");
    }
    expect(str_contains(gcCode(config_path('hq.php')), 'scheduler_gateway_flag'))->toBeFalse()
        ->and(config()->has('hq.scheduler_gateway_flag'))->toBeFalse();
});

it('constructs or resolves no concrete tool outside app/Tools: tools are reached through the registry', function () {
    $offenders = [];
    foreach (gcAppFiles() as $path => $code) {
        if (str_starts_with($path, 'Tools/')) {
            continue;
        }
        if (preg_match('/new\s+\\\\?(?:App\\\\Tools\\\\[\w\\\\]+\\\\)?\w+Tool\s*\(/', $code)
            || preg_match('/(?:app|resolve|make)\(\s*\\\\?(?:App\\\\Tools\\\\[\w\\\\]+\\\\)?\w+Tool::class/', $code)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('reaches the services behind the effectful tools only from their tools, never around them', function () {
    // pattern => the only files (from app/) whose code may contain it, besides the service's own class
    $services = [
        '/\bFactsReconciler\b/' => ['Tools/Commerce/ReconcileShopFactsTool.php', 'Facts/FactsReconciler.php'],
        '/->reconcileStore\s*\(/' => ['Tools/Commerce/ReconcileShopFactsTool.php'],
        '/\bBriefingComposer\b/' => ['Tools/Briefing/ComposeBriefingTool.php', 'Briefing/BriefingComposer.php'],
        '/\bPublicationService\b/' => ['Tools/Publishing/PublishPostTool.php', 'Content/Publishing/PublicationService.php'],
        '/\bAdSpendSync\b/' => ['Tools/Ads/SyncAdSpendTool.php', 'Ads/AdSpendSync.php'],
        '/\bChannelSender\b/' => ['Conversation/Deliveries/ChannelSender.php'],
        '/->(createSalesOrderDraft|recordSalesOrder|cancelSalesOrder)\s*\(/' => ['Tools/Commerce/TakeOrderTool.php', 'Tools/Commerce/CancelSalesOrderTool.php'],
        '/FactsErpSnapshot::(create|updateOrCreate|firstOrCreate|insert|upsert|updateOrInsert)\s*\(/' => ['Tools/Erp/PollErpTool.php'],
        // docs/114 §3: the only writer of a lifted do-not-contact is reached from its tool, after a second person approved.
        '/->liftDoNotContact\s*\(/' => ['Tools/Consent/LiftRestrictionTool.php'],
    ];

    $files = gcAppFiles();
    foreach ($services as $pattern => $permitted) {
        $hits = array_keys(array_filter($files, fn (string $code) => preg_match($pattern, $code) === 1));
        $outside = array_values(array_diff($hits, $permitted));
        expect($outside)->toBe([], "{$pattern} is reached outside its tool: ".implode(', ', $outside));
    }

    // Not vacuous: each tool really does reach its service.
    expect(preg_match('/->reconcileStore\s*\(/', $files['Tools/Commerce/ReconcileShopFactsTool.php']))->toBe(1)
        ->and(preg_match('/FactsErpSnapshot::updateOrCreate\s*\(/', $files['Tools/Erp/PollErpTool.php']))->toBe(1)
        ->and(preg_match('/->createSalesOrderDraft\s*\(/', $files['Tools/Commerce/TakeOrderTool.php']))->toBe(1)
        ->and(preg_match('/->cancelSalesOrder\s*\(/', $files['Tools/Commerce/CancelSalesOrderTool.php']))->toBe(1)
        ->and(preg_match('/->liftDoNotContact\s*\(/', $files['Tools/Consent/LiftRestrictionTool.php']))->toBe(1);
});

it('pins the registered effectful tools to the nine reviewed in docs/105 plus consent.lift_restriction (docs/114) and outcomes.attribute (docs/115), and classifies every tool class in app/Tools', function () {
    $registry = app(ToolRegistry::class);
    $effectful = array_values(array_filter($registry->keys(), fn (string $key) => $registry->get($key)->descriptor()->hasEffect()));
    sort($effectful);

    expect($effectful)->toBe([
        'briefing.compose',
        'commerce.cancel_sales_order',
        'commerce.reconcile_facts',
        'commerce.take_order',
        // docs/114 §3 (S7 follow-up): the two-person lift of a do-not-contact, docs/105 §1.2 row 9.
        'consent.lift_restriction',
        'demo.request_approval',
        'erp.poll_low_stock',
        'erp.poll_overview',
        'messaging.send_reply',
        'messaging.send_whatsapp_reply',
        // docs/115 §4 (ruling Q11): the operator-asserted attribution. R3 write of one HQ ledger row through
        // OutcomeRecorder, permanently human-only, held by no agent. Added here deliberately, with docs/105 §1.2 row 9.
        'outcomes.attribute',
    ]);

    // Every concrete tool class in app/Tools has a readable descriptor, so the effect rule applies to it the day it
    // is registered (the unregistered Phase 5/6 tools included).
    $classes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Tools'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $class = 'App\\Tools\\'.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', substr($file->getPathname(), strlen(app_path('Tools')) + 1)));
        if (! class_exists($class) || ! is_subclass_of($class, Tool::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $descriptor = app($class)->descriptor();
        expect($descriptor->hasEffect())->toBeBool();
        $classes++;
    }
    expect($classes)->toBeGreaterThan(count($registry->keys()));
});
