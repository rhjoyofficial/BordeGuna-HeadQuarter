<?php

/*
 | docs/81 C.9: the ERP stopped answering on its hosting provider's hostname on 7 September 2026, and
 | HQ spent days calling it because a default named it (config/hq.php records the cost). The .env
 | template a host is set up from, and the store seed DomainSeeder writes on every run, still named it.
 | No configuration value and no template line may name it again. A comment that records the history
 | is allowed; a value is not.
 */

function deletedErpHost(): string
{
    return 'erp.srv1808022.hstgr.cloud';
}

it('names the deleted ERP host in no configuration value', function () {
    $offenders = [];
    foreach (glob(config_path('*.php')) as $file) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        if (str_contains($code, deletedErpHost())) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('names the deleted ERP host in no line of the production .env template', function () {
    $template = base_path('../../deploy/.env.example');
    expect(is_file($template))->toBeTrue();

    $lines = array_filter((array) file($template, FILE_IGNORE_NEW_LINES), fn (string $line) => ! str_starts_with(ltrim($line), '#'));

    expect(array_values(array_filter($lines, fn (string $line) => str_contains($line, deletedErpHost()))))->toBe([])
        ->and($lines)->toContain('HQ_ERP_BASE_URL=https://erp.bordeguna.com');
});

it('seeds the ERP counter store on the ERP domain, which is what a re-run of the seeder writes on production', function () {
    $store = collect(config('hq-seed.stores'))->firstWhere('slug', 'erp-counter');

    expect($store['base_url'])->toBe('https://erp.bordeguna.com');
});
