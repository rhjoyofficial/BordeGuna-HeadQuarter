<?php

use Database\Seeders\RolesAndPermissionsSeeder;

/*
 | docs/112 §6 (S6): a playbook decides nothing, held structurally. The loader is reachable from context
 | assembly and nowhere else; the writer and the models are reachable from the playbook namespace, its
 | controller and the models; the resolver, the gateway, the policy checks, the consent ledger and the
 | execution-context resolver do not mention a playbook at all. A new reference anywhere else fails the
 | suite instead of waiting for a reviewer to notice.
 |
 | ⚠ Precise on purpose. Each allowlist names the EXISTING code it admits and why; widening one is a
 | ruling (docs/103 §8 says loaded read-only into context), not a way to make a red test green.
 */

/** The one class outside App\Playbooks that may reach the runtime reader. */
const PLAYBOOK_LOADER_ALLOWED = ['app/Agents/ContextAssembler.php'];

/** Where the writer and the models may be named: the store itself, its operator screen, and the models' own relations. */
const PLAYBOOK_WRITER_ALLOWED_DIRS = ['app/Playbooks/', 'app/Http/Controllers/Playbooks/', 'app/Models/'];

/** The deciding code. Nothing under these may know the word. */
const PLAYBOOK_DECIDING_DIRS = ['app/Gateway/', 'app/Autonomy/', 'app/Consent/', 'app/Execution/', 'app/Tools/', 'app/Workflow/', 'app/Scheduling/', 'app/Http/Middleware/'];

/** Source with comments removed: a docblock may name a class to say it is not wired; code may not. */
function pbCodeOnly(string $php): string
{
    return implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));
}

/** @return array<string, string> relative path => code without comments, for every PHP file under app/ */
function pbAppSources(): array
{
    $out = [];
    $root = app_path();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $relative = 'app/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $out[$relative] = pbCodeOnly((string) file_get_contents($file->getPathname()));
    }
    ksort($out);

    return $out;
}

/** @return list<string> the files whose code matches the pattern, outside the allowed prefixes */
function pbReferencesOutside(string $pattern, array $allowedPrefixes): array
{
    $hits = [];
    foreach (pbAppSources() as $path => $code) {
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }
        if (preg_match($pattern, $code) === 1) {
            $hits[] = $path;
        }
    }

    return $hits;
}

it('lets exactly one class outside App\\Playbooks reach the loader: the context assembler', function () {
    $pattern = '/\bPlaybookLoader\b|\bPlaybookSelection\b/';

    expect(pbReferencesOutside($pattern, ['app/Playbooks/']))->toBe(PLAYBOOK_LOADER_ALLOWED);

    // And the assembler reaches it inside open(), where the bundle is built, not from anywhere that decides.
    $assembler = pbAppSources()['app/Agents/ContextAssembler.php'];
    expect($assembler)->toContain('$this->playbooks->load($context->businessId, $context->brandId)');
});

it('lets the writer and the models be named only by the store, its screen and the models themselves', function () {
    // `Playbooks` the class (not the namespace segment `App\Playbooks\`), and the two models.
    $pattern = '/\bPlaybooks\b(?!\\\\)|\bPlaybookVersion\b|\bPlaybookVersionEvent\b/';

    expect(pbReferencesOutside($pattern, PLAYBOOK_WRITER_ALLOWED_DIRS))->toBe([]);
});

it('keeps the deciding code ignorant of playbooks: resolver, gateway, consent, execution context, tools, workflows, middleware', function () {
    $offenders = [];
    foreach (pbAppSources() as $path => $code) {
        foreach (PLAYBOOK_DECIDING_DIRS as $dir) {
            if (str_starts_with($path, $dir) && preg_match('/playbook/i', $code) === 1) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has the agent runner read the run record from the bundle, never from the store', function () {
    $runner = pbAppSources()['app/Agents/AgentRunner.php'];

    expect($runner)->toContain("'playbooks' => \$this->playbookRecord(\$bundle)")
        ->and($runner)->toContain("isset(\$block->metadata['playbooks'])")
        ->and(preg_match('/\bPlaybook(?:Loader|Selection|Version|VersionEvent|s)\b/', $runner))->toBe(0)
        ->and(preg_match('/App\\\\Playbooks/', $runner))->toBe(0);
});

it('names the four playbook permissions additively, with activation separate from drafting', function () {
    foreach (['hq.playbooks.view', 'hq.playbooks.draft', 'hq.playbooks.review', 'hq.playbooks.activate'] as $permission) {
        expect(RolesAndPermissionsSeeder::PERMISSIONS)->toContain($permission);
    }
    expect(RolesAndPermissionsSeeder::ROLES['brand_manager'])->toContain('hq.playbooks.view', 'hq.playbooks.draft')
        ->not->toContain('hq.playbooks.review', 'hq.playbooks.activate')
        ->and(RolesAndPermissionsSeeder::ROLES['support_agent'])->not->toContain('hq.playbooks.activate');
});

it('seeds no playbook content anywhere', function () {
    $seeders = [];
    foreach (glob(database_path('seeders/*.php')) ?: [] as $file) {
        if (preg_match('/playbook/i', pbCodeOnly((string) file_get_contents($file))) === 1) {
            $seeders[] = basename($file);
        }
    }
    $migration = pbCodeOnly((string) file_get_contents(database_path('migrations/2026_10_10_300000_create_playbook_versions.php')));

    // The seeder names the permissions and nothing else; the migration inserts no row.
    expect($seeders)->toBe(['RolesAndPermissionsSeeder.php'])
        ->and(preg_match('/insert|DB::table\(\'playbook/i', $migration))->toBe(0);
});
