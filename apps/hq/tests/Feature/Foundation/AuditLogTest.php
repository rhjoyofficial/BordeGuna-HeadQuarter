<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

it('writes an audit record with actor, target, correlation id and redacted secrets', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Context::add(['correlation_id' => '11111111-1111-1111-1111-111111111111', 'request_id' => 'req-1']);

    $log = app(Auditor::class)->record('credential.stored', $user, before: null, after: ['provider' => 'meta', 'payload' => 'SECRET', 'nested' => ['token' => 'abc']], reason: 'test');

    expect($log->actor_type)->toBe('human')
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->target_type)->toBe($user->getMorphClass())
        ->and($log->target_id)->toBe((string) $user->id)
        ->and($log->correlation_id)->toBe('11111111-1111-1111-1111-111111111111')
        ->and($log->request_id)->toBe('req-1')
        ->and($log->after['payload'])->toBe('[redacted]')
        ->and($log->after['nested']['token'])->toBe('[redacted]')
        ->and($log->after['provider'])->toBe('meta');
});

it('records system and agent actors', function () {
    $auditor = app(Auditor::class);
    $s = $auditor->recordSystem('job_run.finished', 'scheduled_job', after: ['x' => 1], correlationId: '22222222-2222-2222-2222-222222222222');
    $a = $auditor->recordAgent('support', 'message.sent', 'conversation', after: ['id' => 5]);
    expect($s->actor_type)->toBe('system')->and($s->correlation_id)->toBe('22222222-2222-2222-2222-222222222222')
        ->and($a->actor_type)->toBe('agent')->and($a->actor_label)->toBe('support');
});

it('is append-only at the model level', function () {
    $log = app(Auditor::class)->recordSystem('test.event');
    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
    expect(AuditLog::find($log->id)->action)->toBe('test.event');
});

it('is append-only at the database level on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('database trigger exists only on PostgreSQL');
    }
    $log = app(Auditor::class)->recordSystem('test.event');
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->where('id', $log->id)->value('action'))->toBe('test.event');
});
