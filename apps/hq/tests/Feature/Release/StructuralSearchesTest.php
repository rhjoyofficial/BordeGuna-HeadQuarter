<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 | Ruling docs/120 section C Stage 6: structural searches that prove there is no unreviewed direct effectful-tool
 | invocation, unscoped identity lookup or unauthorized consent/playbook reader (docs/128 section 6).
 |
 | Runs the REAL deploy/tools/structural-searches.php (the file Joy can also run inside the production image) as a
 | separate PHP process, once on this tree (all four searches must pass) and once on a copy of app/ with one planted
 | offender per search (each search must fail and name exactly its planted file): the negative control that proves
 | no search is vacuous.
 */

function structuralSearchScript(): string
{
    return dirname(base_path(), 2).'/deploy/tools/structural-searches.php';
}

/** @return array{0: int, 1: string} exit code and output */
function runStructuralSearches(?string $root): array
{
    $command = [PHP_BINARY, structuralSearchScript()];
    if ($root !== null) {
        array_push($command, '--root', $root);
    }
    $process = new Process($command, base_path());
    $process->setTimeout(120);
    $process->run();

    return [(int) $process->getExitCode(), str_replace("\r\n", "\n", $process->getOutput().$process->getErrorOutput())];
}

/** @return array<string, string> search id => its block of output (the PASS/FAIL line and its offender lines) */
function structuralSearchBlocks(string $output): array
{
    $blocks = [];
    $current = null;
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^(PASS|FAIL) (S\d [a-z-]+) /', $line, $m)) {
            $current = $m[2];
            $blocks[$current] = $line."\n";
        } elseif ($current !== null && str_starts_with($line, '     ')) {
            $blocks[$current] .= $line."\n";
        } else {
            $current = null;
        }
    }

    return $blocks;
}

it('passes all four structural searches on this tree', function () {
    expect(is_file(structuralSearchScript()))->toBeTrue();

    [$exit, $output] = runStructuralSearches(base_path());

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('PASS S1 identity-scope')
        ->and($output)->toContain('PASS S2 tool-gateway')
        ->and($output)->toContain('PASS S3 consent-reader')
        ->and($output)->toContain('PASS S4 playbook-reader')
        ->and($output)->toContain('RESULT: 4 passed, 0 failed');
});

it('fails each search on a planted offender, naming exactly that file (negative control)', function () {
    $root = sys_get_temp_dir().'/hq-structural-'.bin2hex(random_bytes(4));
    File::copyDirectory(app_path(), $root.'/app');

    try {
        $plants = [
            'S1 identity-scope' => ['app/ReleaseControl/LeakyIdentityLookup.php', "\$found = CustomerIdentity::query()->where('external_id', \$value)->first();"],
            'S2 tool-gateway' => ['app/ReleaseControl/DirectToolCall.php', '$result = $tool->execute($request);'],
            'S3 consent-reader' => ['app/ReleaseControl/ConsentPeek.php', '$rows = ConsentProjection::query()->get();'],
            'S4 playbook-reader' => ['app/ReleaseControl/PlaybookPeek.php', "\$rows = DB::table('playbook_versions')->get();"],
        ];
        File::ensureDirectoryExists($root.'/app/ReleaseControl');
        foreach ($plants as [$file, $statement]) {
            File::put($root.'/'.$file, "<?php\n\nnamespace App\\ReleaseControl;\n\nfunction planted()\n{\n    {$statement}\n}\n");
        }

        [$exit, $output] = runStructuralSearches($root);
        $blocks = structuralSearchBlocks($output);

        expect($exit)->toBe(1, $output)->and($output)->toContain('RESULT: 0 passed, 4 failed');
        foreach ($plants as $id => [$file]) {
            expect($blocks[$id] ?? '')->toStartWith("FAIL {$id} ")->toContain($file);
            // Each plant trips its own search only: no other search names it.
            foreach (array_diff_key($blocks, [$id => true]) as $otherBlock) {
                expect($otherBlock)->not->toContain($file);
            }
        }
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses an unknown argument and a root without app/ (usage errors exit 2)', function () {
    $process = new Process([PHP_BINARY, structuralSearchScript(), '--apply'], base_path());
    $process->run();
    expect($process->getExitCode())->toBe(2)->and($process->getErrorOutput())->toContain("unknown argument '--apply'");

    $empty = sys_get_temp_dir().'/hq-structural-empty-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($empty);
    try {
        [$exit] = runStructuralSearches($empty);
        expect($exit)->toBe(2);
    } finally {
        File::deleteDirectory($empty);
    }
});
