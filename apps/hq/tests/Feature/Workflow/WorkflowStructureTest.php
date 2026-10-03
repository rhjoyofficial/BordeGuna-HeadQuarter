<?php

/*
 | docs/102 §6 and docs/101 §11: the WorkflowEngine is never an authority bypass. Held structurally:
 |
 |  - a workflow DEFINITION imports nothing but the workflow seam, and its code names no tool call, no
 |    HTTP, no database and no clock, so its only way to have an effect is to declare a tool step;
 |  - the ENGINE (app/Workflow) imports no connector, adapter, provider, tool or credential class and
 |    no HTTP client, and its only `->execute(` is the Tool Gateway's.
 |
 | ⚠ Adding to an allowlist here is a docs/102 decision, not a way to make a red test green.
 */

/** Namespaces the engine must never import: adapters, providers, tools, credentials, HTTP. */
const WF_ENGINE_FORBIDDEN_IMPORTS = [
    'App\\Connectors\\', 'App\\Commerce\\', 'App\\Erp\\', 'App\\Platform\\', 'App\\Ai\\', 'App\\Research\\',
    'App\\Voice\\', 'App\\Media\\', 'App\\Conversation\\', 'App\\Tools\\', 'App\\Gateway\\Credentials\\',
    'Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client\\', 'GuzzleHttp\\', 'Psr\\Http\\',
];

/** Calls that reach outside PHP without the gateway. */
const WF_RAW_IO = '/\b(curl_\w+|file_get_contents|fopen|fsockopen|stream_socket_client|Http::|Guzzle)/';

/** What a definition's code may not do: call anything, read the clock or the database, or open I/O. */
const WF_DEFINITION_FORBIDDEN = '/(->execute\s*\(|::execute\s*\(|\bDB::|\bnow\s*\(|\btime\s*\(|\bdate\s*\(|Carbon|\bapp\s*\(|\bresolve\s*\()/';

function wfCodeOnly(string $php): string
{
    return implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));
}

/** @return list<string> */
function wfImports(string $code): array
{
    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $code, $m);

    return $m[1];
}

/** @return list<string> every problem with one workflow definition's source */
function wfDefinitionProblems(string $php): array
{
    $code = wfCodeOnly($php);
    $problems = [];
    foreach (wfImports($code) as $import) {
        if (! str_starts_with($import, 'App\\Workflow\\') && $import !== 'Closure') {
            $problems[] = "imports {$import}";
        }
    }
    if (preg_match_all(WF_DEFINITION_FORBIDDEN, $code, $m) > 0) {
        $problems[] = 'calls '.implode(', ', array_unique($m[1]));
    }
    if (preg_match_all(WF_RAW_IO, $code, $m) > 0) {
        $problems[] = 'opens I/O with '.implode(', ', array_unique($m[1]));
    }

    return $problems;
}

/** @return iterable<string, string> */
function wfPhpFiles(string $root): iterable
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            yield str_replace('\\', '/', $file->getPathname()) => (string) file_get_contents($file->getPathname());
        }
    }
}

it('detects a definition that imports a tool, calls it, reads the clock or opens HTTP', function () {
    $php = <<<'PHP'
    <?php
    namespace App\Workflows;
    use App\Workflow\Step;
    use App\Workflow\WorkflowDefinition;
    use App\Gateway\ToolGateway;
    use Illuminate\Support\Facades\Http;
    final class Sneaky extends WorkflowDefinition {
        // ->execute( in a comment is not code
        public function type(): string { return 'x'; }
        public function steps(): array {
            app(ToolGateway::class)->execute($request);
            Http::post('https://example.com');
            return [Step::sleep('s', now()->diffInSeconds())];
        }
    }
    PHP;

    expect(wfDefinitionProblems($php))->toBe([
        'imports App\\Gateway\\ToolGateway',
        'imports Illuminate\\Support\\Facades\\Http',
        'calls app(, ->execute(, now(',
        'opens I/O with Http::',
    ]);
});

it('holds every workflow definition, in the app and in the tests, to the seam alone', function () {
    $found = 0;
    $offenders = [];
    foreach ([...wfPhpFiles(app_path()), ...wfPhpFiles(base_path('tests/Support'))] as $path => $php) {
        if (preg_match('/\bextends\s+(\\\\?App\\\\Workflow\\\\)?WorkflowDefinition\b/', wfCodeOnly($php)) !== 1) {
            continue;
        }
        $found++;
        foreach (wfDefinitionProblems($php) as $problem) {
            $offenders[] = "{$path}: {$problem}";
        }
    }

    // The two test definitions at least: a scan that finds nothing proves nothing.
    expect($found)->toBeGreaterThanOrEqual(2)
        ->and($offenders)->toBe([]);
});

it('keeps the engine away from connectors, adapters, tools, credentials and HTTP; its only executor is the gateway', function () {
    $offenders = [];
    $executes = 0;
    foreach (wfPhpFiles(app_path('Workflow')) as $path => $php) {
        $code = wfCodeOnly($php);
        foreach (wfImports($code) as $import) {
            foreach (WF_ENGINE_FORBIDDEN_IMPORTS as $prefix) {
                if (str_starts_with($import, $prefix)) {
                    $offenders[] = "{$path} imports {$import}";
                }
            }
        }
        if (preg_match_all(WF_RAW_IO, $code, $m) > 0) {
            $offenders[] = "{$path} opens I/O with ".implode(', ', array_unique($m[1]));
        }
        preg_match_all('/(\S+)->execute\s*\(/', $code, $calls);
        foreach ($calls[1] as $receiver) {
            $receiver === '$this->gateway' ? $executes++ : $offenders[] = "{$path} calls {$receiver}->execute(";
        }
    }

    expect($offenders)->toBe([])
        ->and($executes)->toBe(1);
});
