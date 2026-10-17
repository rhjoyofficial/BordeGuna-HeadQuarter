<?php

use Illuminate\Filesystem\Filesystem;

/*
 | docs/136: the business scope audit, pinned.
 |
 | Every controller under app/Http/Controllers and every screen reader under app/Observe/Dashboards is
 | listed here with its verdict. A new file fails the first test until somebody audits it and adds it,
 | which is the point: the approvals and scheduler lists went unscoped because nobody was made to look.
 |
 |   scoped              the file itself narrows by BusinessScope (or a model's visibleTo scope, which
 |                       calls BusinessScope::restrict)
 |   through:<file>      the file hands the person to a reader that does; that reader is checked instead
 |   no business rows    the file reads or writes nothing that belongs to a business (sign-in, 2FA,
 |                       profile, health, inbound webhooks with no person, a time-window value object)
 |
 | The behaviour behind each verdict is in BusinessScopeScreensTest and the per-screen tests.
 */

const SCOPE_AUDIT_CONTROLLERS = [
    'Admin/AdminController.php' => 'scoped',
    'Admin/ToolRegistryController.php' => 'scoped',
    'Agents/AgentController.php' => 'scoped',
    'Approvals/ApprovalController.php' => 'scoped',
    'Ask/AskController.php' => 'scoped',
    'Auth/AuthenticatedSessionController.php' => 'no business rows',
    'Auth/ConfirmablePasswordController.php' => 'no business rows',
    'Auth/EmailVerificationNotificationController.php' => 'no business rows',
    'Auth/EmailVerificationPromptController.php' => 'no business rows',
    'Auth/NewPasswordController.php' => 'no business rows',
    'Auth/PasswordResetLinkController.php' => 'no business rows',
    'Auth/VerifyEmailController.php' => 'no business rows',
    'Controller.php' => 'no business rows',
    'FollowUps/FollowUpController.php' => 'scoped',
    'Health/HealthController.php' => 'no business rows',
    'Identity/CustomerConsentController.php' => 'scoped',
    'Identity/CustomerController.php' => 'scoped',
    'Identity/CustomerQuarantineController.php' => 'scoped',
    'Inbox/InboxController.php' => 'scoped',
    'Observe/BriefingController.php' => 'scoped',
    'Observe/DecisionCorpusController.php' => 'through:app/Decisions/DecisionCorpusReader.php',
    'Observe/ObserveController.php' => 'scoped',
    'Playbooks/PlaybookController.php' => 'scoped',
    'Scheduling/NlScheduleController.php' => 'through:app/Scheduling/Nl/EntityResolver.php',
    'Scheduling/ScheduledJobController.php' => 'scoped',
    'Security/SessionsController.php' => 'no business rows',
    'Security/TwoFactorChallengeController.php' => 'no business rows',
    'Security/TwoFactorController.php' => 'no business rows',
    'Settings/PasswordController.php' => 'no business rows',
    'Settings/ProfileController.php' => 'no business rows',
    'Webhooks/MetaForwardController.php' => 'no business rows',
    'Webhooks/MetaWebhookController.php' => 'no business rows',
    'Webhooks/ShopWebhookController.php' => 'no business rows',
];

const SCOPE_AUDIT_DASHBOARDS = [
    'AiHealthDashboard.php' => 'scoped',
    'ControlPlaneDashboard.php' => 'scoped',
    'CostDashboard.php' => 'scoped',
    'DashboardWindow.php' => 'no business rows',
    // Group-wide by nature: ObserveController refuses the platform screen to a restricted person.
    'PlatformDashboard.php' => 'through:app/Http/Controllers/Observe/ObserveController.php',
    'PostureDashboard.php' => 'scoped',
];

function scopeAuditFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
    }
    sort($files);

    return $files;
}

function scopeAuditUsesScope(string $path): bool
{
    $source = (string) file_get_contents(base_path($path));

    return str_contains($source, 'BusinessScope') || str_contains($source, '->visibleTo(');
}

function scopeAuditCheck(array $verdicts, string $root): void
{
    foreach ($verdicts as $file => $verdict) {
        $path = "{$root}/{$file}";
        if ($verdict === 'scoped') {
            expect(scopeAuditUsesScope($path))->toBeTrue("{$path} is recorded as scoped but names neither BusinessScope nor visibleTo");
        } elseif (str_starts_with($verdict, 'through:')) {
            expect(scopeAuditUsesScope(substr($verdict, 8)))->toBeTrue("{$path} relies on ".substr($verdict, 8).', which does not scope');
        } else {
            expect($verdict)->toBe('no business rows');
        }
    }
}

it('lists every controller with its business scope verdict, so a new one fails until it is audited', function () {
    expect(scopeAuditFiles(app_path('Http/Controllers')))->toBe(array_keys(SCOPE_AUDIT_CONTROLLERS));
    expect(scopeAuditFiles(app_path('Observe/Dashboards')))->toBe(array_keys(SCOPE_AUDIT_DASHBOARDS));
});

it('holds every scoped verdict to a file that actually applies the scope', function () {
    scopeAuditCheck(SCOPE_AUDIT_CONTROLLERS, 'app/Http/Controllers');
    scopeAuditCheck(SCOPE_AUDIT_DASHBOARDS, 'app/Observe/Dashboards');
});

it('keeps visibleTo on every model whose rows the approvals, scheduler and credentials screens list, built on BusinessScope', function () {
    foreach (['Approval', 'ScheduledJob', 'IntegrationCredential', 'FollowUp', 'Opportunity'] as $model) {
        $source = (string) file_get_contents(app_path("Models/{$model}.php"));
        expect($source)->toContain('function scopeVisibleTo(')->toContain('BusinessScope::class)->restrict(');
    }
    // A run has no business of its own and is visible through its job.
    expect((string) file_get_contents(app_path('Models/JobRun.php')))->toContain("whereHas('job', fn (Builder \$job) => \$job->visibleTo(\$user))");
});

it('is proven to fail on a controller that is not in the list', function () {
    // Planted in a copy of the tree, never under app/, so a parallel structural test never sees it.
    $root = sys_get_temp_dir().'/scope-audit-'.uniqid();
    foreach ([...array_keys(SCOPE_AUDIT_CONTROLLERS), 'Planted/PlantedController.php'] as $file) {
        @mkdir(dirname("{$root}/{$file}"), 0777, true);
        file_put_contents("{$root}/{$file}", '<?php
');
    }

    expect(scopeAuditFiles($root))->not->toBe(array_keys(SCOPE_AUDIT_CONTROLLERS))
        ->and(array_values(array_diff(scopeAuditFiles($root), array_keys(SCOPE_AUDIT_CONTROLLERS))))->toBe(['Planted/PlantedController.php']);

    (new Filesystem)->deleteDirectory($root);
});
