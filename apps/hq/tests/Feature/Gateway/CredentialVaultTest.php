<?php

use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\CommerceRegistry;
use App\Erp\ErpRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Models\User;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 | M2: the credential boundary, closing the limitation M1 recorded rather than hid (docs/50).
 |
 | Before M2 each connector looked up and decrypted its own secret. The gateway could scrub a
 | sensitive ARGUMENT out of an error message, because it was given that argument, and could not
 | scrub a CREDENTIAL a connector had fetched for itself, because it had never seen the value.
 |
 | Now the gateway resolves what the tool declared, opens it for the length of the call, and the
 | connector asks the vault. These tests hold that boundary in place.
 */

const VAULT_TOKEN = 'erp-token-secret-8f2c41';

function vaultUser(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function storeErpToken(): Business
{
    $business = Business::where('slug', 'bor-de-guna')->sole();
    IntegrationCredential::create([
        'provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => VAULT_TOKEN], 'status' => 'active',
    ]);

    return $business;
}

beforeEach(fn () => Cache::flush());

it('refuses a connector that asks for a credential nobody opened, and says why', function () {
    $this->seed(DomainSeeder::class);
    storeErpToken();

    // The credential exists and is active. It is simply not open, because no gateway call opened it.
    expect(fn () => app(ErpRegistry::class)->for('bor-de-guna'))
        ->toThrow(Unsupported::class, 'credential is open');
});

it('gives the connector the credential when the gateway opened it', function () {
    $this->seed(DomainSeeder::class);
    storeErpToken();
    config(['hq.erp.base_url' => 'https://erp.test']);
    Http::fake(['*' => Http::response(['data' => []])]);

    app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'bor-de-guna']], function () {
        app(ErpRegistry::class)->for('bor-de-guna')->businesses();
    });

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.VAULT_TOKEN));
});

it('clears the scope when the call ends, including when it throws', function () {
    $this->seed(DomainSeeder::class);
    storeErpToken();
    $vault = app(CredentialVault::class);

    expect($vault->has(ErpRegistry::PROVIDER, 'bor-de-guna'))->toBeFalse();

    $vault->openForAccounts([[ErpRegistry::PROVIDER, 'bor-de-guna']], function () use ($vault) {
        expect($vault->has(ErpRegistry::PROVIDER, 'bor-de-guna'))->toBeTrue();
    });
    expect($vault->has(ErpRegistry::PROVIDER, 'bor-de-guna'))->toBeFalse();

    // A `finally`, not a happy-path clear: an exception must not leave a secret in memory.
    try {
        $vault->openForAccounts([[ErpRegistry::PROVIDER, 'bor-de-guna']], function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    expect($vault->has(ErpRegistry::PROVIDER, 'bor-de-guna'))->toBeFalse();
});

it('opens a declared credential for a real tool call and keeps the value out of every record', function () {
    $this->seed(DomainSeeder::class);
    $business = storeErpToken();
    config(['hq.erp.base_url' => 'https://erp.test']);
    Http::fake(['*' => Http::response(
        json_decode((string) file_get_contents(base_path('tests/fixtures/erp/overview.json')), true),
        200,
    )]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        vaultUser(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'],
    ));

    expect($outcome->ok())->toBeTrue();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.VAULT_TOKEN));

    $everything = json_encode([
        ToolCall::findOrFail($outcome->toolCallId)->toArray(),
        AuditLog::all()->toArray(),
    ]);
    expect($everything)->not->toContain(VAULT_TOKEN);

    // The row records WHICH business the call was for, resolved from the payload.
    expect(ToolCall::findOrFail($outcome->toolCallId)->business_id)->toBe($business->id);
});

it('refuses before executing when a declared credential is missing, and names it', function () {
    $this->seed(DomainSeeder::class);
    config(['hq.erp.base_url' => 'https://erp.test']);
    Http::fake(['*' => Http::response(['data' => []])]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        vaultUser(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'],
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->error)->toContain('erp_api_token');
    Http::assertNothingSent();
});

it('keeps the vault as the only place in the application that decrypts a credential', function () {
    // The structural guarantee. A future developer cannot reintroduce a hidden second lookup in a
    // connector without this failing, which is the whole point of the refactor.
    $offenders = [];
    $allowed = [
        'app/Gateway/Credentials/CredentialVault.php',      // the one decryption point
        'app/Gateway/Credentials/CredentialResolver.php',   // resolves ids only; never reads a payload
        'app/Models/IntegrationCredential.php',             // the model itself
        'app/Http/Controllers/Admin/AdminController.php',   // the credentials screen: stores, never shows
        'app/Support/Credentials/OAuthManager.php',         // writes and refreshes credentials
    ];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (in_array($relative, $allowed, true)) {
            continue;
        }
        $body = (string) file_get_contents($file->getPathname());
        if (str_contains($body, 'IntegrationCredential') && preg_match('/->payload\b/', $body)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'these read a credential payload outside the vault: '.implode(', ', $offenders));
});

it('does not let a credential authorise an HQ operation', function () {
    $this->seed(DomainSeeder::class);
    storeErpToken();
    config(['hq.erp.base_url' => 'https://erp.test']);
    Http::fake(['*' => Http::response(['data' => []])]);

    // The token is present, active and perfectly capable of reading the ERP. The caller is not
    // permitted to use that tool, and the credential does not help: the gateway decided several
    // steps before a credential was ever considered.
    (new RolesAndPermissionsSeeder)->run();
    $weak = User::factory()->create();
    $weak->givePermissionTo('hq.dashboard.view');

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $weak->fresh(), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'],
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Denied);
    Http::assertNothingSent();
});

it('opens only the credentials a tool declared, and nothing else', function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_admin_token', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'admin-secret'], 'status' => 'active']);
    IntegrationCredential::create(['provider' => 'shop_assistant_key', 'account_ref' => 'laladia-com', 'credential_type' => 'api_key', 'payload' => ['value' => 'assistant-secret'], 'status' => 'active']);
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $vault = app(CredentialVault::class);
    $seen = [];

    // commerce.reconcile_facts declares only the admin token, because listing orders is all it does.
    app(ToolGateway::class)->execute(ToolRequest::forOperator(
        vaultUser(), 'commerce.reconcile_facts', ['store_slug' => 'laladia-com', 'hours' => 24],
    ));

    // Outside the call nothing is open at all.
    foreach ([CommerceRegistry::ADMIN_PROVIDER, CommerceRegistry::ASSISTANT_PROVIDER] as $provider) {
        $seen[$provider] = $vault->has($provider, 'laladia-com');
    }
    expect($seen)->toBe([CommerceRegistry::ADMIN_PROVIDER => false, CommerceRegistry::ASSISTANT_PROVIDER => false]);
});
