<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;

/**
 * The single writer of audit records. Actor is resolved from the authenticated user when
 * present, otherwise `system`; agents (Phase 2+) will pass actor_type=agent explicitly.
 *
 * Secrets never reach the audit log: known secret-bearing keys are redacted before storage.
 */
final class Auditor
{
    private const REDACT = ['password', 'password_confirmation', 'secret', 'token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_key', 'access_token', 'refresh_token', 'client_secret', 'authorization', 'remember_token'];

    /**
     * Keys that name a secret when they hold a scalar and a structured record when they hold an
     * array. `payload` is both: on an integration_credentials row it is the encrypted blob, and on
     * a scheduled_jobs row it is the tool's arguments.
     *
     * It used to sit in the list above, which meant that auditing a scheduled job blanked the very
     * arguments the audit exists to record: `{"payload":"[redacted]"}` says something happened
     * while destroying every fact about what. Recursing into an array `payload` and masking only a
     * scalar one keeps the credential protection and gives back the evidence.
     */
    private const REDACT_IF_SCALAR = ['payload'];

    public function record(
        string $action,
        Model|string|null $target = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $actorUserId = null,
        array $context = [],
        ?string $actorLabel = null,
    ): AuditLog {
        $user = $actorUserId ? null : Auth::user();
        $actorId = $actorUserId ?? $user?->getAuthIdentifier();

        return $this->write(
            actorType: $actorId ? 'human' : 'system',
            actorId: $actorId,
            actorLabel: $actorLabel ?? $user?->email ?? ($actorId ? "user:{$actorId}" : 'system'),
            action: $action, target: $target, before: $before, after: $after, reason: $reason, context: $context,
        );
    }

    public function recordSystem(string $action, Model|string|null $target = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?string $correlationId = null, array $context = []): AuditLog
    {
        return $this->write('system', null, 'system', $action, $target, $before, $after, $reason, $context, $correlationId);
    }

    public function recordAgent(string $agentKey, string $action, Model|string|null $target = null, ?array $before = null, ?array $after = null, ?string $reason = null, array $context = []): AuditLog
    {
        return $this->write('agent', null, $agentKey, $action, $target, $before, $after, $reason, $context);
    }

    private function write(string $actorType, ?int $actorId, ?string $actorLabel, string $action, Model|string|null $target, ?array $before, ?array $after, ?string $reason, array $context, ?string $correlationId = null): AuditLog
    {
        [$targetType, $targetId] = match (true) {
            $target instanceof Model => [$target->getMorphClass(), (string) $target->getKey()],
            is_string($target) => [$target, null],
            default => [null, null],
        };

        return AuditLog::create([
            'occurred_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'before' => $before ? $this->redact($before) : null,
            'after' => $after ? $this->redact($after) : null,
            'correlation_id' => $correlationId ?? Context::get('correlation_id'),
            'request_id' => Context::get('request_id'),
            'ip' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) Request::userAgent(), 0, 512),
            'context' => $context ?: null,
        ]);
    }

    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $name = is_string($key) ? strtolower($key) : null;

            if ($name !== null && in_array($name, self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif ($name !== null && in_array($name, self::REDACT_IF_SCALAR, true) && ! is_array($value)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
