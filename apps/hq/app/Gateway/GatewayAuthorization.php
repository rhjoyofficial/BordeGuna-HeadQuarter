<?php

namespace App\Gateway;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Scope\ResolvedScope;
use App\Tools\Tool;

/**
 * The gateway's AUTHORIZATION decision for one request (docs/97 §6 D4): "may this caller do this, here,
 * now?", apart from whether the content is acceptable and apart from running it.
 *
 * Steps 1 to 8 of `ToolGateway`'s order: availability, arguments, scope and the execution context,
 * policy and permission, consent, and (for `ToolGateway::authorize()`) the credential pre-flight. Either
 * the call is authorized (`authorized`), or it waits only for a human decision (`awaitsApproval`: policy
 * said require_approval and nothing else refused), or it is refused, with the status, failure class,
 * outcome class and reason the gateway would have recorded.
 */
final readonly class GatewayAuthorization
{
    /**
     * @param  array<string, mixed>  $args  the validated, normalised arguments (empty when validation refused)
     * @param  list<string>  $secrets
     */
    public function __construct(
        public bool $authorized,
        public ?ToolDescriptor $descriptor,
        public ?Tool $tool = null,
        public array $args = [],
        public array $secrets = [],
        public ?ResolvedScope $scope = null,
        public ?PolicyDecision $decision = null,
        public ?OutcomeStatus $status = null,
        public ?FailureClass $failure = null,
        public ?OutcomeClass $class = null,
        public ?string $reason = null,
        /** Policy said require_approval and every other step passed: authorized pending a human, never executable alone. */
        public bool $awaitsApproval = false,
        /** The refusal is policy's non-allow outcome, which `ToolGateway::execute()` hands to its approval/deny handling. */
        public bool $policyNonAllow = false,
    ) {}

    /** @param  array<string, mixed>  $args */
    public static function refused(?ToolDescriptor $descriptor, OutcomeStatus $status, FailureClass $failure, OutcomeClass $class, string $reason,
        ?Tool $tool = null, array $args = [], ?ResolvedScope $scope = null, ?PolicyDecision $decision = null, bool $policyNonAllow = false): self
    {
        return new self(false, $descriptor, $tool, $args, [], $scope, $decision, $status, $failure, $class, $reason, false, $policyNonAllow);
    }

    /** Authorized, or authorized pending only a human decision: what an A0 proposal needs before it is recorded. */
    public function permitsProposal(): bool
    {
        return $this->authorized || $this->awaitsApproval;
    }

    public function policyOutcome(): PolicyOutcome
    {
        return $this->decision?->outcome ?? PolicyOutcome::Deny;
    }
}
