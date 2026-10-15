<?php

use Illuminate\Support\Facades\DB;

/*
 | docs/121 §4: `deploy/tools/identity-scope-readonly.sql` is what Joy runs on production. It must be
 | read-only by construction (a READ ONLY transaction that is rolled back, SELECT statements only) and
 | it must run without error against the schema, so a typo never reaches production.
 */

function identityScopeSql(): string
{
    return (string) file_get_contents(base_path('../../deploy/tools/identity-scope-readonly.sql'));
}

/** The file without its `--` comment lines, as the statements PostgreSQL will see. */
function identityScopeStatements(): array
{
    $code = collect(preg_split('/\R/', identityScopeSql()))
        ->reject(fn (string $line) => str_starts_with(ltrim($line), '--'))
        ->implode("\n");

    return array_values(array_filter(array_map('trim', explode(';', $code)), fn (string $s) => $s !== ''));
}

it('opens a READ ONLY transaction, rolls it back, and holds nothing but SELECTs between', function () {
    $statements = identityScopeStatements();

    expect($statements[0])->toBe('BEGIN READ ONLY')
        ->and(end($statements))->toBe('ROLLBACK');

    foreach (array_slice($statements, 1, -1) as $statement) {
        $verb = strtoupper(strtok(ltrim($statement), " \n\t("));
        expect(in_array($verb, ['SELECT', 'WITH', 'SET'], true))->toBeTrue("unexpected statement: {$statement}");
        if ($verb === 'SET') {
            expect($statement)->toStartWith('SET LOCAL statement_timeout');
        }
        expect(preg_match('/\b(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE|GRANT|REVOKE|COPY|CALL|DO)\b/i', $statement))
            ->toBe(0, "a write keyword in: {$statement}");
    }
});

it('runs end to end against the schema on a separate connection', function () {
    config(['database.connections.identity_scope_check' => config('database.connections.pgsql')]);
    $pdo = DB::connection('identity_scope_check')->getPdo();

    foreach (identityScopeStatements() as $statement) {
        $pdo->query($statement)->fetchAll();
    }

    expect($pdo->query("SELECT current_setting('transaction_read_only')")->fetchColumn())->toBe('off');
    DB::purge('identity_scope_check');
});
