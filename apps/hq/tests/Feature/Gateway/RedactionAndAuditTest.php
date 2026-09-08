<?php

use App\Gateway\OutcomeStatus;
use App\Gateway\Redaction\Redactor;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AuditLog;
use App\Models\ToolCall;
use App\Models\User;
use App\Scheduling\Tools\ToolRegistry;
use App\Support\Audit\Auditor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tools\SecretEchoTool;

/*
 | Redaction and audit (brief sections 10 and 11).
 |
 | Two opposite failures are being guarded against at once, and it is easy to fix either by causing
 | the other: a secret reaching storage, and redaction so aggressive that the record no longer says
 | what happened. Every test below asserts both halves.
 */

const LEAKY_SECRET = 'sk-live-9f2b7c41aa88d3e5';

const NESTED_SECRET = 'tok-77c1de44aa90';

function redactionUser(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function redactionGateway(): ToolGateway
{
    app(ToolRegistry::class)->register(SecretEchoTool::class);
    app(ToolAvailability::class)->forget();

    return app(ToolGateway::class);
}

beforeEach(function () {
    Cache::flush();
    SecretEchoTool::$throwWithSecret = false;
});

it('keeps a secret out of tool_calls while keeping the ordinary arguments readable', function () {
    $outcome = redactionGateway()->execute(ToolRequest::forOperator(redactionUser(), 'test.secret_echo', [
        'store_slug' => 'laladia-com',
        'hours' => 48,
        'api_key' => LEAKY_SECRET,
        'nested_token' => NESTED_SECRET,
    ]));

    expect($outcome->ok())->toBeTrue();
    $call = ToolCall::findOrFail($outcome->toolCallId);
    $serialised = json_encode([$call->input, $call->output, $call->error]);

    // No secret anywhere on the row, whether the tool declared it or the leaf-key list caught it.
    expect($serialised)->not->toContain(LEAKY_SECRET)
        ->and($serialised)->not->toContain(NESTED_SECRET)
        ->and($call->input['api_key'])->toBe(Redactor::MASK)
        ->and($call->input['nested_token'])->toBe(Redactor::MASK);

    // And the evidence survives. A row reading `{"input":"[redacted]"}` would record that
    // something happened while destroying every fact about what.
    expect($call->input['store_slug'])->toBe('laladia-com')
        ->and($call->input['hours'])->toBe(48)
        ->and($call->output['store'])->toBe('laladia-com');
});

it('scrubs a secret a careless tool interpolated into its own exception message', function () {
    SecretEchoTool::$throwWithSecret = true;

    $outcome = redactionGateway()->execute(ToolRequest::forOperator(redactionUser(), 'test.secret_echo', [
        'store_slug' => 'laladia-com',
        'api_key' => LEAKY_SECRET,
    ]));

    expect($outcome->status)->toBe(OutcomeStatus::Failed);

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->error)->not->toContain(LEAKY_SECRET)
        // The failure is still legible: knowing the upstream rejected a key is the useful half.
        ->and($call->error)->toContain('upstream rejected key');
});

it('never writes a secret into the audit log', function () {
    redactionGateway()->execute(ToolRequest::forOperator(redactionUser(), 'test.secret_echo', [
        'store_slug' => 'laladia-com',
        'api_key' => LEAKY_SECRET,
        'nested_token' => NESTED_SECRET,
    ]));

    $all = json_encode(AuditLog::all()->toArray());
    expect($all)->not->toContain(LEAKY_SECRET)->and($all)->not->toContain(NESTED_SECRET);
});

it('recurses into a structured payload instead of blanking it, and still masks a scalar one', function () {
    // The M1 fix. `payload` used to sit in the Auditor key list, so auditing a scheduled job
    // blanked the very arguments the audit exists to record. It means two different things: an
    // encrypted credential blob when it is a scalar, and a tool's arguments when it is an array.
    $auditor = app(Auditor::class);

    $structured = $auditor->redact(['payload' => ['store_slug' => 'laladia-com', 'hours' => 48, 'token' => LEAKY_SECRET]]);

    expect($structured['payload']['store_slug'])->toBe('laladia-com')
        ->and($structured['payload']['hours'])->toBe(48)
        ->and($structured['payload']['token'])->toBe('[redacted]');

    $scalar = $auditor->redact(['payload' => LEAKY_SECRET]);
    expect($scalar['payload'])->toBe('[redacted]');
});

it('masks a declared nested path and a wildcard list path', function () {
    $redactor = app(Redactor::class);

    $out = $redactor->redact([
        'keep' => 'visible',
        'creds' => ['user' => 'hq', 'pass' => LEAKY_SECRET],
        'items' => [['sku' => 'A', 'key' => 'k1'], ['sku' => 'B', 'key' => 'k2']],
    ], ['creds.pass', 'items.*.key']);

    expect($out['keep'])->toBe('visible')
        ->and($out['creds']['user'])->toBe('hq')
        ->and($out['creds']['pass'])->toBe(Redactor::MASK)
        ->and($out['items'][0]['sku'])->toBe('A')
        ->and($out['items'][0]['key'])->toBe(Redactor::MASK)
        ->and($out['items'][1]['key'])->toBe(Redactor::MASK);
});

it('keeps the audit log append-only, with the database refusing to rewrite history', function () {
    $log = app(Auditor::class)->recordSystem('test.event', 'thing', after: ['a' => 1]);

    // The Postgres trigger from Phase 0. M1 writes far more audit rows than before, so it is worth
    // re-proving that nothing in the gateway weakened the guarantee they rest on.
    // Naming the concrete exception rather than the Throwable interface: Pest reads a bare
    // interface name as a message substring, which would pass for the wrong reason.
    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditLog::find($log->id)->action)->toBe('test.event');
});
