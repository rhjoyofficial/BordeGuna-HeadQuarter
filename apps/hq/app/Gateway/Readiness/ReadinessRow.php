<?php

namespace App\Gateway\Readiness;

/**
 * One line of the readiness table (ADR-043, docs/74 C.3): one target, one provider, and whether
 * HQ could execute against it right now, with the reason. Never a value.
 *
 * ⭐ Two facts, not one (docs/77 E.1, ChatGPT's point 2 of 16 September). `wouldAttempt()` says
 * nothing structural stops HQ from sending the call; `verified()` says the provider accepted the
 * credential the last time it was asked. The table used to show one "executable" column, which read
 * "yes" for a key nothing had ever accepted and for an account with no credit left.
 */
final readonly class ReadinessRow
{
    /** The provider accepted the credential on the last check or live call. */
    public const VERIFIED = 'verified';

    /** A credential is stored and nothing has accepted or rejected it yet. */
    public const CREDENTIAL_UNVERIFIED = 'credential_unverified';

    public const CREDENTIAL_MISSING = 'credential_missing';

    /** Expired, or rejected on authentication; the gateway refuses to use it (ADR-043). */
    public const CREDENTIAL_INVALID = 'credential_invalid';

    /** The provider refused because the account has no credit. The credential is not judged. */
    public const PROVIDER_BLOCKED_BY_CREDIT = 'provider_blocked_by_credit';

    /** The provider refused a permission the credential lacks. Not condemned; a person must look. */
    public const PROVIDER_UNAUTHORIZED = 'provider_unauthorized';

    /** The last check found the provider down, slow or unreachable. Says nothing about the credential. */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** The last check got an answer HQ could not use. Says nothing about the credential. */
    public const CONNECTOR_ERROR = 'connector_error';

    public const UNSUPPORTED = 'unsupported';

    public const DISABLED = 'disabled';

    /** States in which a call would go out and a failure, if any, is not known to be lasting. */
    private const TRANSIENT = [self::VERIFIED, self::CREDENTIAL_UNVERIFIED, self::PROVIDER_UNAVAILABLE, self::CONNECTOR_ERROR];

    /** States in which HQ would still send the call, whatever the provider is likely to answer. */
    private const ATTEMPTED = [...self::TRANSIENT, self::PROVIDER_BLOCKED_BY_CREDIT, self::PROVIDER_UNAUTHORIZED];

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

    /** Nothing structural stops HQ from sending the call: a credential with a value, a switch that is on. */
    public function wouldAttempt(): bool
    {
        return in_array($this->state, self::ATTEMPTED, true);
    }

    /** The provider accepted the credential the last time it was asked. */
    public function verified(): bool
    {
        return $this->state === self::VERIFIED;
    }

    /**
     * A capability HQ supports that cannot serve a call, for a reason a person must act on: no
     * credential, a rejected one, an account with no credit, a missing permission, a switch turned off.
     * A store with no connector is not one, and neither is a provider that was briefly down.
     */
    public function incident(): bool
    {
        return $this->supported && ! in_array($this->state, self::TRANSIENT, true);
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
            'would_attempt' => $this->wouldAttempt(),
            'verified' => $this->verified(),
            'incident' => $this->incident(),
        ];
    }
}
