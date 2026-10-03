<?php

use App\Models\IntegrationCredential;
use App\Support\Credentials\CredentialKinds;
use Inertia\Testing\AssertableInertia as Assert;

/*
 | docs/95 §5 "Operations" (stream W2b item 4): the Credentials screen offers, and the store accepts, only
 | the credential kinds the code reads. The structural test below finds every kind the application reads
 | (a vault lookup, a declared CredentialRequirement, a provider constant) and fails when one is missing
 | from `CredentialKinds`, so a new reader cannot ship without a way to store its value.
 */

/** Every credential kind named by a read in app/, found from the source rather than from the list. */
function credentialKindsReadByTheCode(): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $patterns = [
            // provider constants: PROVIDER, CREDENTIAL, CREDENTIAL_PROVIDER, TOKEN_PROVIDER, SECRET_PROVIDER, ...
            "/const\\s+(?:[A-Z_]*PROVIDER|CREDENTIAL)\\s*=\\s*'([a-z0-9_]+_(?:token|key|secret))'/",
            // a literal kind handed straight to the vault or to a requirement
            "/->(?:secret|optional|active|accountsFor)\\(\\s*'([a-z0-9_]+)'/",
            "/openForAccounts\\(\\s*\\[\\s*\\[\\s*'([a-z0-9_]+)'/",
            "/new\\s+CredentialRequirement\\(\\s*'([a-z0-9_]+)'/",
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $m);
            foreach ($m[1] as $kind) {
                $found[$kind] = true;
            }
        }
    }
    ksort($found);

    return array_keys($found);
}

it('lists every credential kind the code reads, and nothing it does not', function () {
    $read = credentialKindsReadByTheCode();

    expect($read)->not->toBeEmpty()
        ->and(array_values(array_diff($read, CredentialKinds::keys())))->toBe([])
        ->and(array_values(array_diff(CredentialKinds::keys(), $read)))->toBe([]);

    foreach (CredentialKinds::all() as $key => $kind) {
        expect($kind['account_ref'])->not->toBe('')->and($kind['purpose'])->not->toBe('', $key);
    }
});

it('refuses to store a kind no code reads, and stores one it does', function () {
    $owner = $this->hqUser('owner');

    foreach (['meta', 'telegram', 'mimsms', 'whatsapp_token'] as $wrong) {
        $this->actingAsHq($owner)->post('/credentials', ['provider' => $wrong, 'account_ref' => 'x', 'credential_type' => 'token', 'secret' => 's3cret-value'])
            ->assertSessionHasErrors('provider');
    }
    expect(IntegrationCredential::count())->toBe(0);

    $this->actingAsHq($owner)->post('/credentials', ['provider' => 'meta_page_token', 'account_ref' => '100085015853889', 'credential_type' => 'token', 'secret' => 's3cret-value'])
        ->assertSessionHasNoErrors();
    expect(IntegrationCredential::where('provider', 'meta_page_token')->count())->toBe(1);
});

it('hands the screen the kinds with their account rule and purpose, and still lists rows stored under an old name', function () {
    $owner = $this->hqUser('owner');
    IntegrationCredential::create(['provider' => 'meta', 'account_ref' => 'legacy', 'credential_type' => 'token', 'payload' => ['value' => 'x'], 'status' => 'active']);

    $this->actingAsHq($owner)->get('/credentials')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('credentials/index')
        ->has('kinds', count(CredentialKinds::keys()))
        ->where('kinds.0', ['key' => 'shop_admin_token', 'account_ref' => CredentialKinds::ACCOUNT_STORE, 'purpose' => CredentialKinds::all()['shop_admin_token']['purpose']])
        ->where('credentials.0.provider', 'meta'));
});
