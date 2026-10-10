<?php

use App\FollowUps\Workflow\FollowUpWorkflow;
use App\Tools\ToolRegistry;
use App\Workflow\Step;
use App\Workflow\WorkflowDefinitions;

/*
 | docs/113 sections 8, 11 and 12: S4 part 1 has NO outbound path. Held structurally, so a send cannot be
 | added under App\FollowUps without this file changing in the same reviewed commit:
 |
 |  - nothing under app/FollowUps imports a tool, the gateway, a connector, a platform adapter, the
 |    conversation outbound path, a provider or an HTTP client, and nothing calls ->execute(;
 |  - the follow-up workflow definition has no tool step: a sleep and a signal wait, in that order;
 |  - no registered tool is a follow-up tool, so no agent can propose, approve or act on one;
 |  - the S3 seam imports nothing from App\Outcomes.
 */

const FU_FORBIDDEN_IMPORTS = [
    'App\\Tools\\', 'App\\Gateway\\ToolGateway', 'App\\Gateway\\ToolRequest', 'App\\Connectors\\', 'App\\Platform\\',
    'App\\Conversation\\', 'App\\Commerce\\', 'App\\Erp\\', 'App\\Ai\\', 'App\\Outcomes\\', 'App\\Scheduling\\',
    'Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client\\', 'GuzzleHttp\\', 'Psr\\Http\\',
];

function fuCode(string $php): string
{
    return implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));
}

/** @return iterable<string, string> */
function fuPhpFiles(string $root): iterable
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            yield str_replace('\\', '/', $file->getPathname()) => fuCode((string) file_get_contents($file->getPathname()));
        }
    }
}

it('keeps App\\FollowUps and its models and controller away from tools, the gateway, connectors, platforms, outbound and HTTP', function () {
    $files = [
        ...iterator_to_array(fuPhpFiles(app_path('FollowUps'))),
        app_path('Models/FollowUp.php') => fuCode(file_get_contents(app_path('Models/FollowUp.php'))),
        app_path('Models/Opportunity.php') => fuCode(file_get_contents(app_path('Models/Opportunity.php'))),
        ...iterator_to_array(fuPhpFiles(app_path('Http/Controllers/FollowUps'))),
    ];
    expect(count($files))->toBeGreaterThanOrEqual(10);

    $offenders = [];
    foreach ($files as $path => $code) {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $code, $m);
        foreach ($m[1] as $import) {
            foreach (FU_FORBIDDEN_IMPORTS as $prefix) {
                if (str_starts_with($import, $prefix)) {
                    $offenders[] = "{$path} imports {$import}";
                }
            }
        }
        if (preg_match('/->(execute|send|dispatch)\s*\(/', $code, $call) === 1) {
            $offenders[] = "{$path} calls ->{$call[1]}(";
        }
        if (preg_match('/\b(curl_\w+|file_get_contents|fsockopen|stream_socket_client|Http::)/', $code, $io) === 1) {
            $offenders[] = "{$path} opens I/O with {$io[1]}";
        }
    }

    expect($offenders)->toBe([]);
});

it('declares a follow-up workflow with no tool step: sleep until due, then wait for a person', function () {
    $steps = (new FollowUpWorkflow)->steps();

    expect(array_map(fn (Step $s) => [$s->name, $s->kind], $steps))->toBe([
        [FollowUpWorkflow::STEP_UNTIL_DUE, Step::SLEEP],
        [FollowUpWorkflow::STEP_PERSON_DECISION, Step::SIGNAL],
    ])
        ->and(array_filter($steps, fn (Step $s) => $s->kind === Step::TOOL || $s->toolKey !== null))->toBe([])
        ->and($steps[1]->signal)->toBe(FollowUpWorkflow::SIGNAL_PERSON_DECIDED)
        ->and((new FollowUpWorkflow)->listensTo())->toBe([])
        ->and(fuCode(file_get_contents(app_path('FollowUps/Workflow/FollowUpWorkflow.php'))))->not->toContain('Step::tool');
});

it('lists the follow-up type in the catalogue, and registers no follow-up tool for any agent', function () {
    expect(app(WorkflowDefinitions::class)->has(FollowUpWorkflow::TYPE))->toBeTrue();

    $keys = app(ToolRegistry::class)->keys();
    expect($keys)->not->toBeEmpty()
        ->and(array_values(array_filter($keys, fn ($k) => str_contains($k, 'follow') || str_contains($k, 'opportunit'))))->toBe([]);

    foreach (config('hq.tools', []) as $class) {
        expect($class)->not->toContain('FollowUp')->not->toContain('Opportunit');
    }
});
