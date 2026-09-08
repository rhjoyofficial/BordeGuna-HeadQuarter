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
        ];
    }
}
