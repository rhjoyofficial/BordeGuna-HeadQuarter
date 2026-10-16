<?php

/*
 | Stage 6 structural searches (ruling docs/120 section C Stage 6; docs/128 section 6).
 |
 |   php deploy/tools/structural-searches.php [--root <the Laravel app root, the directory holding app/>]
 |   docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php -- --root /var/www/html < deploy/tools/structural-searches.php
 |
 | Framework-free and READ ONLY: it reads PHP source files under <root>/app with file_get_contents and prints one
 | line per search, `PASS <id> <what>` or `FAIL <id> <what>: <offenders>`, then `RESULT: <n> passed, <m> failed`.
 | Exit 0 when every search passes, 1 otherwise, 2 on a usage error. It opens no database, no network, writes no
 | file, so it may run inside the production image against the deployed code.
 |
 | The four searches, each the source-level twin of a Pest pin that already guards it on every CI run:
 |   S1 identity-scope  No lookup of customer_identities by channel or external id that does not name a business.
 |                      Twin of tests/Feature/Identity/IdentityBusinessScopeTest.php ("finds no lookup ...", W1).
 |   S2 tool-gateway    Only the Tool Gateway invokes execute()/reconcile()/preview() on a tool; no tool is built
 |                      outside app/Tools; no effectful service is reached around its tool.
 |                      Twin of tests/Feature/Gateway/GatewayCoverageStructureTest.php (docs/105, docs/116).
 |   S3 consent-reader  The consent ledger, its projection and their tables are named only by the reviewed files.
 |                      New with W7: no Pest pin existed for consent readers; tests/Feature/Release/
 |                      StructuralSearchesTest.php now runs this search on every CI run.
 |   S4 playbook-reader The playbook loader is reached only by the context assembler; the writer, models and tables
 |                      only by the store, its screen and the models; the deciding code never says "playbook".
 |                      Twin of tests/Feature/Playbooks/PlaybookStructureTest.php (docs/112, docs/120 item 23).
 |
 | ⚠ An allow-list here widens only with the same reviewed change that widens its Pest twin. A red search is a
 | finding to review, never a list to extend to make it green.
 */

$args = $argv ?? [];
$root = null;
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--root' && isset($args[$i + 1])) {
        $root = $args[++$i];
    } elseif ($args[$i] === '--') {
        continue;
    } else {
        fwrite(STDERR, "structural-searches: unknown argument '{$args[$i]}'. Usage: php structural-searches.php [--root <app root>]\n");
        exit(2);
    }
}
if ($root === null) {
    $root = is_dir(getcwd().'/app') ? getcwd() : dirname(__DIR__, 2).'/apps/hq';
}
$root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
if ($root === '' || ! is_dir($root.'/app')) {
    fwrite(STDERR, "structural-searches: no app/ directory under the root '{$root}'\n");
    exit(2);
}

/** @return array<string, array{raw: string, code: string}> 'app/...' => raw source and source without comments */
function ssSources(string $root): array
{
    $out = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $raw = (string) file_get_contents($file->getPathname());
        $code = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $code .= $token[1];
                }
            } else {
                $code .= $token;
            }
        }
        $relative = 'app/'.str_replace('\\', '/', substr(str_replace('\\', '/', $file->getPathname()), strlen($root.'/app') + 1));
        $out[$relative] = ['raw' => $raw, 'code' => $code];
    }
    ksort($out);

    return $out;
}

/** @param list<string> $prefixes */
function ssUnder(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

$sources = ssSources($root);

// ── S1: every identity lookup names a business (W1's pin, the same statement rule, on the raw source as W1 reads it)
function ssIdentityScope(array $sources): array
{
    $offenders = [];
    foreach ($sources as $path => $src) {
        $code = $src['raw'];
        if (! preg_match('/CustomerIdentity|customer_identities|identities\(\)/', $code)) {
            continue;
        }
        foreach (preg_split('/;\s*\n/', $code) as $statement) {
            $touches = preg_match('/CustomerIdentity::|customer_identities|->identities\(\)/', $statement);
            $byValue = preg_match("/where\\(\\s*'(?:[a-z_]+\\.)?(external_id|channel)'/", $statement)
                || preg_match("/where\\(\\s*\\[\\s*'(external_id|channel)'/", $statement)
                || (preg_match('/updateOrCreate|firstOrCreate|firstOrNew/', $statement) && $touches);
            if ($touches && $byValue && ! preg_match('/business_id|->key\\(/', $statement)) {
                $offenders[] = $path.': '.substr(trim((string) preg_replace('/\s+/', ' ', $statement)), 0, 140);
            }
        }
    }

    return $offenders;
}

// ── S2: no effectful-tool invocation outside the Tool Gateway (GatewayCoverageStructureTest's four rules)
const SS_TOOL_CALL_SITES = [
    'app/Gateway/ToolGateway.php' => ['$tool->execute', '$tool->reconcile', '$tool->preview', '$this->preview'],
    'app/Scheduling/Jobs/RunScheduledJob.php' => ['$gateway->execute'],
    'app/Agents/AgentRunner.php' => ['$this->gateway->execute'],
    'app/Gateway/Approval/ApprovalExecutor.php' => ['$this->gateway->execute'],
    'app/Http/Controllers/Inbox/InboxController.php' => ['$this->gateway->execute'],
    'app/Http/Controllers/Identity/CustomerConsentController.php' => ['$this->gateway->execute'],
    'app/Workflow/WorkflowRunner.php' => ['$this->gateway->execute'],
    'app/Console/Commands/FactsReconcile.php' => ['$gateway->execute'],
    'app/Console/Commands/IdentityRepair.php' => ['$gateway->execute'],                       // human-only repair tool, docs/126 (twin: GatewayCoverageStructureTest)
    'app/Http/Controllers/Identity/CustomerQuarantineController.php' => ['$this->gateway->execute'], // repair from the quarantine screen, docs/126
    'app/Scheduling/Commands/SchedulerTick.php' => ['$workflows->reconcile'],
    'app/Content/Publishing/PublicationService.php' => ['$this->reconcile'],
    'app/Ask/AskHq.php' => ['$this->contexts->preview'],
    'app/Http/Controllers/Scheduling/NlScheduleController.php' => ['$this->nl->preview'],
];
const SS_EFFECT_SERVICES = [
    '/\bFactsReconciler\b/' => ['app/Tools/Commerce/ReconcileShopFactsTool.php', 'app/Facts/FactsReconciler.php'],
    '/->reconcileStore\s*\(/' => ['app/Tools/Commerce/ReconcileShopFactsTool.php'],
    '/\bBriefingComposer\b/' => ['app/Tools/Briefing/ComposeBriefingTool.php', 'app/Briefing/BriefingComposer.php'],
    '/\bPublicationService\b/' => ['app/Tools/Publishing/PublishPostTool.php', 'app/Content/Publishing/PublicationService.php'],
    '/\bAdSpendSync\b/' => ['app/Tools/Ads/SyncAdSpendTool.php', 'app/Ads/AdSpendSync.php'],
    '/\bChannelSender\b/' => ['app/Conversation/Deliveries/ChannelSender.php'],
    '/->(createSalesOrderDraft|recordSalesOrder|cancelSalesOrder)\s*\(/' => ['app/Tools/Commerce/TakeOrderTool.php', 'app/Tools/Commerce/CancelSalesOrderTool.php'],
    '/FactsErpSnapshot::(create|updateOrCreate|firstOrCreate|insert|upsert|updateOrInsert)\s*\(/' => ['app/Tools/Erp/PollErpTool.php'],
    '/->liftDoNotContact\s*\(/' => ['app/Tools/Consent/LiftRestrictionTool.php'],
];

function ssToolGateway(array $sources): array
{
    $offenders = [];
    foreach ($sources as $path => $src) {
        $code = $src['code'];
        preg_match_all('/(\$[A-Za-z_][\w]*(?:->[A-Za-z_]\w*)*)->(execute|reconcile|preview)\s*\(/', $code, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $receiver = $match[1].'->'.$match[2];
            if (! in_array($receiver, SS_TOOL_CALL_SITES[$path] ?? [], true)) {
                $offenders[] = "{$path}: {$receiver}( is not a reviewed call site";
            }
        }
        if (preg_match('/\[\s*\$\w+\s*,\s*[\'"](execute|reconcile|preview)[\'"]\s*\]/', $code)) {
            $offenders[] = "{$path}: names a tool method as a callable";
        }
        if (preg_match('/->get\([^)]*\)\s*->(execute|reconcile)\s*\(/', $code)) {
            $offenders[] = "{$path}: calls a tool fetched from the registry";
        }
        if (! str_starts_with($path, 'app/Tools/')
            && (preg_match('/new\s+\\\\?(?:App\\\\Tools\\\\[\w\\\\]+\\\\)?\w+Tool\s*\(/', $code)
                || preg_match('/(?:app|resolve|make)\(\s*\\\\?(?:App\\\\Tools\\\\[\w\\\\]+\\\\)?\w+Tool::class/', $code))) {
            $offenders[] = "{$path}: constructs or resolves a concrete tool outside app/Tools";
        }
        if (str_contains($code, 'scheduler.gateway') || str_contains($code, 'scheduler_gateway_flag')) {
            $offenders[] = "{$path}: reads the retired scheduler.gateway control";
        }
        foreach (SS_EFFECT_SERVICES as $pattern => $permitted) {
            if (preg_match($pattern, $code) && ! in_array($path, $permitted, true)) {
                $offenders[] = "{$path}: reaches {$pattern} around its tool";
            }
        }
    }

    return $offenders;
}

// ── S3: consent readers. Every file that names the consent ledger, its projection or their tables, with why.
const SS_CONSENT_ALLOWED = [
    'app/Consent/',                                           // the ledger, the gate, the operations, thread identity
    'app/Models/ConsentEvent.php',                            // the ledger's model
    'app/Models/ConsentProjection.php',                       // the projection's model
    'app/Models/FollowUp.php',                                // relation: the consent event a follow-up recorded (docs/113)
    'app/Conversation/ConversationService.php',               // asks ConsentLedger::permits before a send (docs/108)
    'app/Conversation/OutboundPolicy.php',                    // the outbound check through the ledger (docs/108)
    'app/Conversation/Simulation/ConversationSimulator.php',  // proves a simulation wrote no consent row
    'app/Customer360/Customer360Reader.php',                  // the consent section of Customer 360 (docs/109), hq.consent.view
    'app/FollowUps/FollowUpProposalService.php',              // asks ConsentLedger::permits as the gateway does (docs/113)
    'app/Http/Controllers/Identity/CustomerConsentController.php', // the consent screen, hq.consent.* (docs/114)
    'app/Identity/Quarantine/CrossBusinessDetector.php',      // counts consent rows across a business boundary (docs/117)
    'app/Observe/Dashboards/PostureDashboard.php',            // a count of consent events, no row content
    'app/Observe/Retention/RetentionPolicy.php',              // keeps consent rows out of the purge (docs/108)
    'app/Agents/Shadow/ShadowEvidence.php',                   // counts agent-authored consent events (must stay zero)
    'app/Tools/Consent/LiftRestrictionTool.php',              // the two-person lift, through the gateway (docs/114)
    'app/Gateway/ToolGateway.php',                            // the ConsentGate check every effectful send passes (docs/108)
    'app/Identity/Repair/RepairPlanner.php',                  // lists standing restrictions for the human re-record list, reads only (docs/126, A3 point 9)
    'app/Identity/Repair/RepairApplier.php',                  // re-records do-not-contact/withdrawn on the target via ConsentLedger, never a grant (docs/126, A3 point 9)
];

function ssConsentReaders(array $sources): array
{
    $offenders = [];
    $pattern = '/\bConsentProjection\b|\bConsentEvent\b|\bconsent_state\b|\bconsent_events\b|\bConsentLedger\b|\bConsentOperations\b|\bConsentGate\b/';
    foreach ($sources as $path => $src) {
        if (preg_match($pattern, $src['code']) && ! ssUnder($path, SS_CONSENT_ALLOWED)) {
            $offenders[] = "{$path}: names the consent ledger, projection or tables and is not a reviewed reader";
        }
    }

    return $offenders;
}

// ── S4: playbook readers (PlaybookStructureTest's allow-lists, verbatim)
const SS_PLAYBOOK_LOADER_ALLOWED = ['app/Agents/ContextAssembler.php'];
const SS_PLAYBOOK_WRITER_ALLOWED = ['app/Playbooks/', 'app/Http/Controllers/Playbooks/', 'app/Models/'];
const SS_PLAYBOOK_DECIDING = ['app/Gateway/', 'app/Autonomy/', 'app/Consent/', 'app/Execution/', 'app/Tools/', 'app/Workflow/', 'app/Scheduling/', 'app/Http/Middleware/'];

function ssPlaybookReaders(array $sources): array
{
    $offenders = [];
    foreach ($sources as $path => $src) {
        $code = $src['code'];
        if (preg_match('/\bPlaybookLoader\b|\bPlaybookSelection\b/', $code) && ! ssUnder($path, ['app/Playbooks/']) && ! in_array($path, SS_PLAYBOOK_LOADER_ALLOWED, true)) {
            $offenders[] = "{$path}: reaches the playbook loader";
        }
        if (preg_match('/\bPlaybooks\b(?!\\\\)|\bPlaybookVersion\b|\bPlaybookVersionEvent\b/', $code) && ! ssUnder($path, SS_PLAYBOOK_WRITER_ALLOWED)) {
            $offenders[] = "{$path}: names the playbook writer or models";
        }
        if (preg_match('/\bplaybook_versions?\b|\bplaybook_version_events\b/', $code) && ! ssUnder($path, SS_PLAYBOOK_WRITER_ALLOWED)) {
            $offenders[] = "{$path}: names a playbook table (a raw-query reader)";
        }
        if (ssUnder($path, SS_PLAYBOOK_DECIDING) && preg_match('/playbook/i', $code)) {
            $offenders[] = "{$path}: deciding code mentions a playbook";
        }
    }

    return $offenders;
}

$searches = [
    'S1 identity-scope' => ['no unscoped identity lookup (every customer_identities lookup by value names a business)', ssIdentityScope($sources)],
    'S2 tool-gateway' => ['no direct effectful-tool invocation outside the Tool Gateway', ssToolGateway($sources)],
    'S3 consent-reader' => ['no unauthorized consent reader (ledger, projection, tables named only by the reviewed files)', ssConsentReaders($sources)],
    'S4 playbook-reader' => ['no unauthorized playbook reader (loader, writer, models, tables; deciding code ignorant)', ssPlaybookReaders($sources)],
];

echo 'structural searches over '.count($sources)." PHP files under {$root}/app\n";
$passed = 0;
$failed = 0;
foreach ($searches as $id => [$what, $offenders]) {
    if ($offenders === []) {
        echo "PASS {$id} {$what}\n";
        $passed++;
    } else {
        echo "FAIL {$id} {$what}: ".count($offenders)." offender(s)\n";
        foreach ($offenders as $offender) {
            echo "     {$offender}\n";
        }
        $failed++;
    }
}
echo "RESULT: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
