<?php

namespace App\Gateway\Readiness;

/**
 * One line of the readiness table (ADR-043, docs/74 C.3): one target, one provider, and whether
 * HQ could execute against it right now, with the reason. Never a value.
 */
final readonly class ReadinessRow
{
    public const READY = 'ready';

    public const UNVERIFIED = 'unverified';

    public const CREDENTIAL_MISSING = 'credential_missing';

    public const CREDENTIAL_INVALID = 'credential_invalid';

    public const UNSUPPORTED = 'unsupported';

    public const DISABLED = 'disabled';

    /**
     * @param  string  $targetKind  `store`, `business` or `hq`
     * @param  bool  $supported  HQ holds a connector for this target; false is unsupported by design, not an incident
     * @param  array{at: string, ok: bool, class: string|null, message: string|null, source: string}|null  $lastResult
     */
    public function __construct(
        public string $targetKind,
        public string $target,
        public string $targetName,
        public string $provider,
        public string $accountRef,
        public bool $supported,
        public string $state,
        public string $reason,
        public ?int $credentialId = null,
        public ?string $credentialStatus = null,
        public bool $hasPayload = false,
        public ?string $lastVerifiedAt = null,
        public ?array $lastResult = null,
    ) {}

    /** HQ would attempt a call: verified, or holding a credential nothing has yet rejected. */
    public function executable(): bool
    {
        return $this->state === self::READY || $this->state === self::UNVERIFIED;
    }

    /**
     * A capability HQ supports that cannot execute (point 9 of docs/74 A): the one kind of gap an
     * operator must act on. A missing token for a store with no connector is not one.
     */
    public function incident(): bool
    {
        return $this->supported && ! $this->executable();
    }

    public function toArray(): array
    {
        return [
            'target_kind' => $this->targetKind,
            'target' => $this->target,
            'target_name' => $this->targetName,
            'provider' => $this->provider,
            'account_ref' => $this->accountRef,
            'supported' => $this->supported,
            'state' => $this->state,
            'reason' => $this->reason,
            'credential_id' => $this->credentialId,
            'credential_status' => $this->credentialStatus,
            'has_payload' => $this->hasPayload,
            'last_verified_at' => $this->lastVerifiedAt,
            'last_result' => $this->lastResult,
            'executable' => $this->executable(),
            'incident' => $this->incident(),
        ];
    }
}
