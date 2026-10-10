<?php

namespace App\Gateway\Descriptor;

use InvalidArgumentException;

/**
 * A tool's whole contract with the control plane (docs/48 D.1).
 *
 * It lives on the tool class rather than in configuration because these are statements about what
 * the code does. A risk tier that could be edited in a config file would be a risk tier that could
 * be edited to make an expensive action look cheap; `config('hq.tools')` stays the outermost
 * capability switch and says only whether a tool exists at all.
 *
 * `inputSchema()` deliberately stays on the Tool interface, where it already was and where the
 * scheduler already reads it. The output schema lives here because nothing consumed it before.
 */
final readonly class ToolDescriptor
{
    /**
     * @param  string  $key  stable forever; scheduled_jobs rows reference it by name
     * @param  string  $version  semantic; a stored approval names a version, so a major bump must refuse an old approval
     * @param  list<string>  $supportedBusinesses  business slugs, or ['*']
     * @param  list<string>  $supportedStores  store slugs, or ['*']
     * @param  list<CredentialRequirement>  $credentials  what the gateway must find before executing
     * @param  list<string>  $idempotencyKeyFrom  argument paths the derived key is built from
     * @param  list<string>  $redact  argument and output paths replaced before anything is persisted
     * @param  array  $outputSchema  what callers and, later, a model may rely on
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $title,
        public string $purpose,
        public RiskTier $riskTier,
        public string $permission,
        public string $auditAction,
        public ToolScope $scope = ToolScope::Hq,
        public ReadWrite $readWrite = ReadWrite::Read,
        /**
         * Whether an agent may EXECUTE this tool today. False is the shipped default and reads as
         * ActionClass::HumanOnly.
         */
        public bool $agentExecutable = false,
        /**
         * Whether being human-only is PERMANENT rather than merely not yet earned.
         *
         * ⛔ These two flags answer different questions and must not be collapsed again.
         * `agentExecutable: false` alone means "no agent has been cleared for this yet", which is a
         * STAGE: evaluation evidence can end it. `permanentlyHumanOnly: true` means "no agent may
         * ever run this", which is a DECISION: moving money, changing permissions, changing autonomy
         * itself, overriding consent, altering the audit trail.
         *
         * Both refuse an agent identically today, so this changes no behaviour. What it changes is
         * that the two become distinguishable before there are enough tools for the difference to
         * be lost, which is the whole point of declaring it now (docs/53 section R, ADR-025).
         */
        public bool $permanentlyHumanOnly = false,
        public array $supportedBusinesses = ['*'],
        public array $supportedStores = ['*'],
        public ?string $policyClass = null,
        /**
         * Where the tool's TARGET comes from when the caller did not put it in the request context.
         *
         * Form: `store:args.store_slug` or `business:args.business_slug`, or both separated by `|`.
         *
         * This exists because the Phase 1 scheduled jobs name their target in the PAYLOAD and leave
         * the request's business and store null, which is how they were written long before a
         * gateway existed. A business-scoped tool must still name a business; this is the
         * recognition that naming it in `business_slug` IS naming it. See ScopeResolver.
         */
        public ?string $scopeFrom = null,
        public array $credentials = [],
        public bool $idempotent = true,
        public array $idempotencyKeyFrom = [],
        public int $timeoutSeconds = 60,
        public RetryPolicy $retry = new RetryPolicy,
        public CostHint $costHint = new CostHint,
        public array $redact = [],
        public array $outputSchema = [],
        /**
         * How long a derived idempotency key may replay a recorded result.
         *
         * Zero means never: two genuine calls that happen to look alike both run. A caller that
         * knows two invocations are the same logical operation says so by supplying an explicit
         * idempotency key, and that always replays. Only set this above zero for a tool where
         * repeating the work within the window is provably wasted rather than merely similar.
         */
        public int $replayWithinSeconds = 0,
        /**
         * ── Phase 2.5 additions (ADR-026). All additive, all defaulted to the safest value, so no
         * existing descriptor changes and no existing behaviour changes.
         *
         * They exist because a rule that cannot be expressed here gets written inside a tool's body
         * instead, where the control plane cannot see it, cannot audit it, and cannot notice when
         * the next tool forgets it.
         */
        public Channel $channel = Channel::Internal,
        public MonetaryImpact $monetaryImpact = MonetaryImpact::None,
        public ?ConsentRequirement $consentRequired = null,
        /**
         * Whether a person outside the group sees the effect.
         *
         * Different from `readWrite` and from `riskTier`: a customer-visible action is
         * reputationally irreversible even when it is technically undoable. Deleting a message you
         * sent does not unsend it.
         */
        public bool $customerFacing = false,
        public Reversibility $reversibility = Reversibility::NoEffect,
        /** The tool that compensates this one, where compensation is how it is undone. */
        public ?string $compensatingTool = null,
        public DataClass $dataClass = DataClass::Internal,
        /**
         * How many targets one invocation covers (ADR-040). Defaults to HQ-wide for an unscoped
         * tool; a business- or store-scoped tool is expected to say `OnePerBusiness` or
         * `OnePerStore`, and the agent catalogue writes that out as a sentence the model reads.
         */
        public ToolCardinality $cardinality = ToolCardinality::HqWide,
        /**
         * Whether the person who asked for an approval of this tool may also decide it (docs/83 C.3).
         *
         * True is every tool's behaviour until now: a person who asks directly may approve their own
         * request, and only an agent's requester who is not its enabler is barred (docs/81 C.2).
         * False makes the separation absolute for this tool: whoever asked, directly or through an
         * agent, never decides. `Approval::separationOfDutiesReason()`, the one rule every door
         * reads, applies it. With one operator, a tool that says false can be approved by nobody,
         * which is the intended state for an order write before a second approver exists.
         */
        public bool $requesterMayDecide = true,
        /**
         * Which role-boundary kind running this tool IS (docs/88 point 12): `outbound_message`,
         * `publication` or `customer_reply` (`RoleBoundary::TOOL_ACTIONS`), or null for a tool that
         * reaches nobody. The gateway refuses a call whose kind the agent's role forbids, or that the
         * run's own message asked for and the runtime refused. A tool on an outbound channel that says
         * nothing is read as `outbound_message` (`effectiveRoleAction()`): failing closed.
         */
        public ?string $roleAction = null,
        /**
         * docs/97 §6 D4: the content checks the gateway runs on this tool's call AFTER it has authorized
         * it and BEFORE it executes (`App\Gateway\PreExecution\PreExecutionSafetyChecks::CHECKS`), e.g.
         * `reply_script` for a customer-facing reply. Opt-in per tool; empty runs none.
         *
         * @var list<string>
         */
        public array $preExecutionChecks = [],
    ) {
        // A tool cannot be permanently human-only AND agent-executable. Refusing the contradiction
        // at construction means the mistake fails the build at the line that made it, rather than
        // becoming a silently agent-executable tool that a document still calls human-only.
        if ($permanentlyHumanOnly && $agentExecutable) {
            throw new InvalidArgumentException(
                "[{$key}] declares permanentlyHumanOnly and agentExecutable together. "
                .'A permanently human-only tool can never be granted to an agent, so this is a contradiction, not a configuration.'
            );
        }

        // A customer-facing outbound tool MUST name the consent class the gateway checks. Without
        // this, the first channel tool written in a hurry is a channel tool that contacts people
        // with nothing consulted, and it would look exactly like every other descriptor.
        if ($customerFacing && $channel->isOutbound() && $consentRequired === null) {
            throw new InvalidArgumentException(
                "[{$key}] is customer-facing on channel [{$channel->value}] but declares no consentRequired. "
                .'Name the consent class the gateway must check, or the tool cannot reach a person.'
            );
        }

        // Compensable means "undone by doing something else". Saying so without naming what leaves
        // the claim unactionable at exactly the moment somebody needs to act on it.
        if ($reversibility === Reversibility::Compensable && ($compensatingTool === null || $compensatingTool === '')) {
            throw new InvalidArgumentException(
                "[{$key}] declares Reversibility::Compensable without naming a compensatingTool. "
                .'A compensable effect that does not say what compensates it is an irreversible one with better manners.'
            );
        }

        // Credentials are the vault's, never a tool's declared payload class.
        if ($dataClass === DataClass::Secret) {
            throw new InvalidArgumentException(
                "[{$key}] declares dataClass Secret. Tools do not carry credentials; CredentialVault opens them for the length of a call."
            );
        }

        if ($roleAction !== null && ! in_array($roleAction, self::ROLE_ACTIONS, true)) {
            throw new InvalidArgumentException(
                "[{$key}] declares roleAction [{$roleAction}], which is not one of [".implode(', ', self::ROLE_ACTIONS).'].'
            );
        }
    }

    /**
     * The role-boundary kinds a tool can perform (docs/88 point 12). The same three as
     * `App\Agents\RoleBoundary::TOOL_ACTIONS`, written here so the gateway does not depend on the
     * agents' detector; a test holds the two lists equal.
     */
    public const ROLE_ACTIONS = ['outbound_message', 'publication', 'customer_reply'];

    /**
     * The role-boundary kind a call to this tool performs: what it declares, or `outbound_message`
     * for a tool on an outbound channel that declares nothing (fail closed), or null for a tool that
     * reaches nobody.
     */
    public function effectiveRoleAction(): ?string
    {
        if ($this->roleAction !== null) {
            return $this->roleAction;
        }

        return $this->channel->isOutbound() ? 'outbound_message' : null;
    }

    /**
     * Whether a call to this tool changes something: it writes, or it sits at risk tier R3 or above.
     *
     * ⭐ The ONE definition of an effectful tool (docs/101 §11 "effectful tool governance", docs/105, S1).
     * Every effectful call goes through the Tool Gateway on every path: the agent runtime puts each one
     * to the gateway (at A0 as `authorize()`, never executed), the scheduler runs each one through the
     * gateway whatever `scheduler.gateway` says, and `GatewayCoverageStructureTest` fails the suite if
     * anything outside the gateway invokes a tool. Declared on the descriptor, not re-derived by each
     * caller, so the runner, the scheduler and the tests cannot drift apart.
     */
    public function hasEffect(): bool
    {
        return $this->readWrite === ReadWrite::Write || $this->riskTier->atLeast(RiskTier::R3);
    }

    /**
     * Why an agent may not execute this, or null when it may.
     *
     * The distinction is surfaced rather than merely stored: a refusal that says "not yet" invites a
     * different next step from one that says "never", and the difference belongs in the audit trail
     * where somebody reads it, not only in a boolean nobody prints.
     */
    public function agentRefusalReason(): ?string
    {
        if ($this->permanentlyHumanOnly) {
            return "[{$this->key}] is permanently human-only. No approval, configuration or autonomy grant can ever let an agent run it.";
        }

        if (! $this->agentExecutable) {
            return "[{$this->key}] is not yet cleared for agents. An agent may propose it; a human must perform it.";
        }

        return null;
    }

    public function supportsBusiness(?string $slug): bool
    {
        return in_array('*', $this->supportedBusinesses, true)
            || ($slug !== null && in_array($slug, $this->supportedBusinesses, true));
    }

    public function supportsStore(?string $slug): bool
    {
        return in_array('*', $this->supportedStores, true)
            || ($slug !== null && in_array($slug, $this->supportedStores, true));
    }

    public function requiresBusinessContext(): bool
    {
        return $this->scope === ToolScope::Business || $this->scope === ToolScope::Store;
    }

    /** Everything a screen or a listing may show. Contains no secret by construction. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'title' => $this->title,
            'purpose' => $this->purpose,
            'risk_tier' => $this->riskTier->value,
            'risk_label' => $this->riskTier->label(),
            'baseline_action' => $this->riskTier->baseline()->value,
            'permission' => $this->permission,
            'audit_action' => $this->auditAction,
            'scope' => $this->scope->value,
            'read_write' => $this->readWrite->value,
            'agent_executable' => $this->agentExecutable,
            'permanently_human_only' => $this->permanentlyHumanOnly,
            'supported_businesses' => $this->supportedBusinesses,
            'supported_stores' => $this->supportedStores,
            'scope_from' => $this->scopeFrom,
            'policy_class' => $this->policyClass,
            'idempotent' => $this->idempotent,
            'timeout_seconds' => $this->timeoutSeconds,
            'replay_within_seconds' => $this->replayWithinSeconds,
            'credentials' => array_map(fn (CredentialRequirement $c) => $c->toArray(), $this->credentials),
            'redact' => $this->redact,
            'channel' => $this->channel->value,
            'monetary_impact' => $this->monetaryImpact->value,
            'consent_required' => $this->consentRequired?->toArray(),
            'customer_facing' => $this->customerFacing,
            'reversibility' => $this->reversibility->value,
            'compensating_tool' => $this->compensatingTool,
            'data_class' => $this->dataClass->value,
            'requester_may_decide' => $this->requesterMayDecide,
        ];
    }
}
