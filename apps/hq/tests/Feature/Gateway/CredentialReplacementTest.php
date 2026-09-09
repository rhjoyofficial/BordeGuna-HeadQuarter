<?php

use App\Erp\ErpRegistry;
use App\Gateway\Credentials\AmbiguousCredential;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AuditLog;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Support\Credentials\CredentialWriter;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 | Post-M4 hardening, 9 Sep: storing a credential is a REPLACEMENT (docs/55).
 |
 | The defect these tests hold shut, recorded on 8 Sep during the M4 safety gate: the credentials
 | screen always `create()`d and `CredentialVault::active()` was an unordered `->first()`. Storing a
 | replacement key therefore left TWO active rows for one identity, and PostgreSQL was free to keep
 | returning the OLD one - so a key that had been replaced was still the key every call used. The
 | screen said stored, the audit log said stored, and nothing about the 401 that followed pointed
 | at the row.
 |
 | Three layers, tested here in order: the writer replaces, the database refuses a second active
 | row, and the vault refuses to guess if one ever appears anyway.
 */

const CRED_OLD_SECRET = 'sk-ant-api03-old-2f7a41c9e6b04d18';

const CRED_NEW_SECRET = 'sk-ant-api03-new-8b3e57d0a1c94f62';

const CRED_PROVIDER = 'anthropic_api_key';

const CRED_ACCOUNT = 'hq';

function writeCredential(string $secret): IntegrationCredential
{
    return app(CredentialWriter::class)->replace(
        CRED_PROVIDER, CRED_ACCOUNT, ['value' => $secret], ['credential_type' => 'api_key'],
    );
}

function activeCount(string $provider = CRED_PROVIDER, string $account = CRED_ACCOUNT): int
{
    return IntegrationCredential::query()
        ->where('provider', $provider)->where('account_ref', $account)->where('status', 'active')->count();
}

/**
 * Drops the unique index for the length of one test, so the ambiguous state can be created at all.
 *
 * That is the point rather than a cheat: the application can no longer produce two active rows, and
 * the vault's refusal exists for the database that predates this index or was edited by hand.
 * RefreshDatabase wraps each test in a transaction and PostgreSQL rolls DDL back with everything
 * else, so the index is present again for the next test.
 */
function withoutTheActiveCredentialIndex(): void
{
    DB::statement('DROP INDEX IF EXISTS integration_credentials_one_active_per_identity');
}

function forceSecondActiveRow(string $secret = CRED_NEW_SECRET): IntegrationCredential
{
    return IntegrationCredential::create([
        'provider' => CRED_PROVIDER, 'account_ref' => CRED_ACCOUNT, 'credential_type' => 'api_key',
        'payload' => ['value' => $secret], 'status' => 'active',
    ]);
}

beforeEach(fn () => Cache::flush());

it('stores the first credential for an identity, encrypted, and audits it', function () {
    $credential = writeCredential(CRED_OLD_SECRET);

    expect($credential->status)->toBe('active')
        ->and(activeCount())->toBe(1)
        ->and(AuditLog::where('action', 'credential.stored')->count())->toBe(1)
        ->and(AuditLog::where('action', 'credential.revoked')->count())->toBe(0);

    // Encrypted at rest: the column holds the cast's ciphertext, not the key.
    $stored = (string) DB::table('integration_credentials')->where('id', $credential->id)->value('payload');
    expect($stored)->not->toBeEmpty()->and($stored)->not->toContain(CRED_OLD_SECRET);

    // And it is the row the vault resolves.
    expect(app(CredentialVault::class)->active(CRED_PROVIDER, CRED_ACCOUNT)?->id)->toBe($credential->id);
});

it('replaces rather than accumulates, and the new key is the one that is used', function () {
    $old = writeCredential(CRED_OLD_SECRET);
    $new = writeCredential(CRED_NEW_SECRET);

    // A replacement is a NEW row on purpose: a new id and a new credential.stored record are the
    // only evidence an operator has that a save actually reached the server rather than expiring
    // on a stale tab.
    expect($new->id)->not->toBe($old->id)
        ->and(activeCount())->toBe(1)
        ->and(app(CredentialVault::class)->active(CRED_PROVIDER, CRED_ACCOUNT)?->id)->toBe($new->id);

    // The regression that mattered: the vault opens the REPLACEMENT, not the key it replaced.
    withCredentials([[CRED_PROVIDER, CRED_ACCOUNT]], function () {
        expect(app(CredentialVault::class)->secret(CRED_PROVIDER, CRED_ACCOUNT))->toBe(CRED_NEW_SECRET);
    });
});

it('retires the old credential through the existing lifecycle and deletes nothing', function () {
    $old = writeCredential(CRED_OLD_SECRET);
    $new = writeCredential(CRED_NEW_SECRET);
    $old->refresh();

    expect($old->status)->toBe('revoked')
        ->and($old->hasPayload())->toBeFalse()
        ->and($old->trashed())->toBeFalse();

    // Both rows are still there. A revoked row is the record that a key was in use until a moment,
    // which is exactly what an incident asks about later.
    expect(IntegrationCredential::withTrashed()->count())->toBe(2)
        ->and($new->fresh()->meta['replaces'] ?? null)->toBe([$old->id]);
});

it('audits both sides of the replacement and names the credential that superseded', function () {
    $old = writeCredential(CRED_OLD_SECRET);
    $new = writeCredential(CRED_NEW_SECRET);

    $revoked = AuditLog::where('action', 'credential.revoked')->sole();
    $stored = AuditLog::where('action', 'credential.stored')->latest('id')->first();

    expect(AuditLog::where('action', 'credential.stored')->count())->toBe(2)
        ->and($revoked->target_id)->toBe((string) $old->id)
        ->and($revoked->reason)->toContain("superseded by credential #{$new->id}")
        ->and($revoked->before['status'])->toBe('active')
        ->and($revoked->after['status'])->toBe('revoked')
        ->and($stored->after['replaced_credential_ids'])->toBe([$old->id]);
});

it('heals an identity that already had two active rows, instead of adding a third', function () {
    withoutTheActiveCredentialIndex();
    $first = writeCredential(CRED_OLD_SECRET);
    $second = forceSecondActiveRow();

    expect(activeCount())->toBe(2);

    $third = app(CredentialWriter::class)->replace(
        CRED_PROVIDER, CRED_ACCOUNT, ['value' => 'sk-ant-api03-third-0000'], ['credential_type' => 'api_key'],
    );

    expect(activeCount())->toBe(1)
        ->and($third->fresh()->status)->toBe('active')
        ->and($first->fresh()->status)->toBe('revoked')
        ->and($second->fresh()->status)->toBe('revoked')
        ->and(AuditLog::where('action', 'credential.revoked')->count())->toBe(2);
});

it('refuses deterministically when two credentials are active for one identity', function () {
    withoutTheActiveCredentialIndex();
    $first = writeCredential(CRED_OLD_SECRET);
    $second = forceSecondActiveRow();
    $vault = app(CredentialVault::class);

    // Not "the newest wins" and not "the oldest wins": HQ does not pick an identity for the
    // operator, because the rows do not record which one was meant.
    foreach (range(1, 3) as $attempt) {
        try {
            $vault->active(CRED_PROVIDER, CRED_ACCOUNT);
            $this->fail("attempt {$attempt} resolved an ambiguous credential instead of refusing");
        } catch (AmbiguousCredential $e) {
            expect($e->credentialIds)->toBe([$first->id, $second->id])
                ->and($e->getMessage())->toContain('#'.$first->id)->toContain('#'.$second->id)
                ->and($e->getMessage())->not->toContain(CRED_OLD_SECRET)
                ->and($e->getMessage())->not->toContain(CRED_NEW_SECRET);
        }
    }

    // Revoking one is all it takes to make the identity answerable again.
    $second->forceFill(['status' => 'revoked', 'payload' => null])->save();
    expect($vault->active(CRED_PROVIDER, CRED_ACCOUNT)?->id)->toBe($first->id);
});

it('lets the database refuse a second active row even when application code forgets', function () {
    writeCredential(CRED_OLD_SECRET);

    // Wrapped so the failure rolls back to a SAVEPOINT: a poisoned transaction would take the rest
    // of the test with it on PostgreSQL.
    expect(fn () => DB::transaction(fn () => forceSecondActiveRow()))->toThrow(QueryException::class);
    expect(activeCount())->toBe(1);

    // The index is about ACTIVE rows only. Retired history is still free to pile up.
    IntegrationCredential::create([
        'provider' => CRED_PROVIDER, 'account_ref' => CRED_ACCOUNT, 'credential_type' => 'api_key',
        'payload' => ['value' => 'sk-ant-api03-retired-0000'], 'status' => 'revoked',
    ]);
    expect(IntegrationCredential::count())->toBe(2);
});

it('refuses a tool call whose credential is ambiguous, names the rows, and leaks no secret', function () {
    $this->seed(DomainSeeder::class);
    withoutTheActiveCredentialIndex();
    config(['hq.erp.base_url' => 'https://erp.test']);
    Http::fake(['*' => Http::response(['data' => []])]);

    foreach ([CRED_OLD_SECRET, CRED_NEW_SECRET] as $secret) {
        IntegrationCredential::create([
            'provider' => ErpRegistry::PROVIDER, 'account_ref' => 'bor-de-guna', 'credential_type' => 'token',
            'payload' => ['value' => $secret], 'status' => 'active',
        ]);
    }

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $this->hqUser('owner'), 'erp.poll_overview', ['business_slug' => 'bor-de-guna'],
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->error)->toContain('ambiguous')
        ->and($outcome->error)->toContain(ErpRegistry::PROVIDER);
    Http::assertNothingSent();

    $everything = json_encode([ToolCall::all()->toArray(), AuditLog::all()->toArray()]);
    expect($everything)->not->toContain(CRED_OLD_SECRET)->and($everything)->not->toContain(CRED_NEW_SECRET);
});

it('replaces from the credentials screen, which is the path the operator actually uses', function () {
    $owner = $this->hqUser('owner');

    $this->actingAsHq($owner)->post('/credentials', [
        'provider' => CRED_PROVIDER, 'account_ref' => CRED_ACCOUNT,
        'credential_type' => 'api_key', 'secret' => CRED_OLD_SECRET,
    ])->assertRedirect();

    $this->actingAsHq($owner)->post('/credentials', [
        'provider' => CRED_PROVIDER, 'account_ref' => CRED_ACCOUNT,
        'credential_type' => 'api_key', 'secret' => CRED_NEW_SECRET,
    ])->assertRedirect();

    expect(activeCount())->toBe(1)
        ->and(IntegrationCredential::count())->toBe(2)
        ->and(AuditLog::where('action', 'credential.stored')->count())->toBe(2)
        ->and(AuditLog::where('action', 'credential.revoked')->count())->toBe(1);

    withCredentials([[CRED_PROVIDER, CRED_ACCOUNT]], function () {
        expect(app(CredentialVault::class)->secret(CRED_PROVIDER, CRED_ACCOUNT))->toBe(CRED_NEW_SECRET);
    });

    // The screen posts the key in clear over the session; nothing that survives the request holds it.
    $everything = json_encode(AuditLog::all()->toArray());
    expect($everything)->not->toContain(CRED_OLD_SECRET)->and($everything)->not->toContain(CRED_NEW_SECRET);
});

it('keeps a different provider or account out of the replacement', function () {
    $hq = writeCredential(CRED_OLD_SECRET);
    $other = app(CredentialWriter::class)->replace(
        CRED_PROVIDER, 'other-account', ['value' => 'sk-ant-api03-other-0000'], ['credential_type' => 'api_key'],
    );
    $elsewhere = app(CredentialWriter::class)->replace(
        'shop_admin_token', CRED_ACCOUNT, ['value' => 'shop-token-0000'], ['credential_type' => 'token'],
    );

    writeCredential(CRED_NEW_SECRET);

    // Only the identity being replaced is touched. Revoking a neighbour would be the same silent
    // damage in the opposite direction.
    expect($hq->fresh()->status)->toBe('revoked')
        ->and($other->fresh()->status)->toBe('active')
        ->and($elsewhere->fresh()->status)->toBe('active')
        ->and(activeCount())->toBe(1);
});
