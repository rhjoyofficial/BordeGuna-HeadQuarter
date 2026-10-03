<?php

namespace App\Gateway;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * WHY a call did not succeed, on one of three sides (ADR-043, docs/74 C.1).
 *
 * ⭐ "HQ did not permit this" and "HQ permitted this but the outside system failed" are two facts,
 * and gate 8 showed what happens when one stamp covers both: a shop that rejected its token was
 * graded as a control-plane refusal. `OutcomeStatus` says what happened to the call; `FailureClass`
 * says how a connector failed; this says whose fault it was, which is the fact a grader, a screen
 * and a readiness check each need first.
 */
enum OutcomeClass: string
{
    // ── Control plane: HQ said no, before anything left the building ──────────────────────────
    case PolicyDenied = 'policy_denied';
    case PermissionDenied = 'permission_denied';
    case ConsentDenied = 'consent_denied';
    case AutonomyDenied = 'autonomy_denied';
    case ScopeDenied = 'scope_denied';
    case ApprovalRequired = 'approval_required';
    case InvalidArguments = 'invalid_arguments';
    case CapabilityUnavailable = 'capability_unavailable';
    /**
     * docs/97 §6 D4: authorized, and refused BEFORE execution by a content check the tool opts into (the
     * reply's script, docs/92). Not a permission or policy denial: the caller may do this, and this
     * content may not go. The runtime may repair it once (docs/92 A.5).
     */
    case ContentPolicyRejected = 'content_policy_rejected';

    // ── External: HQ said yes, and the outside did not answer as it should ────────────────────
    case CredentialMissing = 'credential_missing';
    case CredentialInvalid = 'credential_invalid';
    case ProviderUnauthorized = 'provider_unauthorized';
    case ProviderUnavailable = 'provider_unavailable';
    /** The provider answered, and refused because the account behind the credential has no funds (docs/77 E.1). */
    case ProviderBlockedByCredit = 'provider_blocked_by_credit';
    case ConnectorError = 'connector_error';
    case NetworkError = 'network_error';
    case Timeout = 'timeout';

    // ── HQ's own fault or doubt ───────────────────────────────────────────────────────────────
    case InternalError = 'internal_error';
    case Uncertain = 'uncertain';

    public const SIDE_CONTROL_PLANE = 'control_plane';

    public const SIDE_EXTERNAL = 'external';

    public const SIDE_HQ = 'hq';

    public function side(): string
    {
        return match ($this) {
            self::PolicyDenied, self::PermissionDenied, self::ConsentDenied, self::AutonomyDenied, self::ScopeDenied,
            self::ApprovalRequired, self::InvalidArguments, self::CapabilityUnavailable, self::ContentPolicyRejected => self::SIDE_CONTROL_PLANE,
            self::CredentialMissing, self::CredentialInvalid, self::ProviderUnauthorized, self::ProviderUnavailable,
            self::ProviderBlockedByCredit, self::ConnectorError, self::NetworkError, self::Timeout => self::SIDE_EXTERNAL,
            self::InternalError, self::Uncertain => self::SIDE_HQ,
        };
    }

    /** HQ refused it: a proposal the control plane turned down, which a grader may hold against the model. */
    public function isControlPlane(): bool
    {
        return $this->side() === self::SIDE_CONTROL_PLANE;
    }

    /** HQ permitted it and the outside failed: readiness evidence, never a refusal. */
    public function isExternal(): bool
    {
        return $this->side() === self::SIDE_EXTERNAL;
    }

    /** The sentence a screen puts beside the class. */
    public function label(): string
    {
        return match ($this) {
            self::PolicyDenied => 'refused by policy',
            self::PermissionDenied => 'refused: no permission',
            self::ConsentDenied => 'refused: no consent',
            self::AutonomyDenied => 'held for a human: autonomy level',
            self::ScopeDenied => 'refused: no valid target named',
            self::ApprovalRequired => 'waiting for approval',
            self::InvalidArguments => 'refused: invalid arguments',
            self::CapabilityUnavailable => 'not available: no tool, switch or adapter',
            self::ContentPolicyRejected => 'refused before execution: the content does not meet a content policy',
            self::CredentialMissing => 'no credential stored',
            self::CredentialInvalid => 'credential known to be rejected',
            self::ProviderUnauthorized => 'the outside system rejected the credential',
            self::ProviderUnavailable => 'the outside system was unavailable',
            self::ProviderBlockedByCredit => 'the provider account has no credit',
            self::ConnectorError => 'the outside system answered with an error',
            self::NetworkError => 'the outside system could not be reached',
            self::Timeout => 'the outside system did not answer in time',
            self::InternalError => 'an error inside HQ',
            self::Uncertain => 'the outcome is uncertain',
        };
    }

    /**
     * The class of a thrown connector exception, read from the exception itself so the same 401
     * means the same thing whether a shop, the ERP or a future provider threw it. Unsupported with
     * an HTTP code is an authorization answer from the far side (the adapters throw it for 401 and
     * 403); Unsupported without one is HQ having no adapter, which is a capability gap, not a
     * provider fault.
     */
    public static function fromThrowable(Throwable $e): self
    {
        $code = (int) $e->getCode();
        $message = mb_strtolower($e->getMessage());

        return match (true) {
            $e instanceof Uncertain => self::Uncertain,
            $e instanceof ConnectionException => str_contains($message, 'timed out') || str_contains($message, 'timeout')
                ? self::Timeout : self::NetworkError,
            $e instanceof Unavailable => self::ProviderUnavailable,
            $e instanceof Unsupported => $code === 401 || $code === 403 ? self::ProviderUnauthorized : self::CapabilityUnavailable,
            $e instanceof Refused, $e instanceof ValidationFailed => self::ConnectorError,
            default => self::InternalError,
        };
    }
}
