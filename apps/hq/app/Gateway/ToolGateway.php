<?php

namespace App\Gateway;

use App\Consent\ConsentGate;
use App\Gateway\Approval\ApprovalGuard;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialResolver;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\PolicyEngine;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Recording\IdempotencyKeyFactory;
use App\Gateway\Recording\ToolCallRecorder;
use App\Gateway\Redaction\Redactor;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Scope\ResolvedScope;
use App\Gateway\Scope\ScopeResolver;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\Approval;
use App\Models\Business;
use App\Models\Store;
use App\Models\ToolCall;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single door between any caller and any effect (ADR-016, docs/48 D).
 *
 * The order of the steps is not arbitrary. Each is placed so the cheapest and most certain refusals
 * happen before anything expensive, anything irreversible, and above all before a credential is
 * resolved:
 *
 *   1. registry + runtime switch     -> unavailable
 *   2. schema validation             -> invalid          (before policy, before any credential)
 *   3. scope resolution + business scope -> denied
 *   4/5. policy engine, which owns the permission intersection and the guardrail rules
 *                                    -> allow | require_approval | deny | dry_run | confirm
 *   6. approval                      -> M3; in M1 an approval requirement is a refusal, not a wait
 *   7. idempotency                   -> replay a recorded result, or reconcile an uncertain one
 *   8. credential pre-flight         -> unavailable, with the missing credential named
 *   9. execute
 *  10. record: tool_calls always, audit_logs for R3 and above
 *
 * Two things this class must never become: a place that reads prose, and a place with a second
 * path. Both would dissolve the guarantee the rest of the design rests on.
 */
final class ToolGateway
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolAvailability $availability,
        private readonly ArgumentValidator $validator,
        private readonly ScopeResolver $scopes,
        private readonly PolicyEngine $policy,
        private readonly BusinessScope $businessScope,
        private readonly CredentialResolver $credentials,
        private readonly CredentialVault $vault,
        private readonly IdempotencyKeyFactory $keys,
        private readonly ToolCallRecorder $recorder,
        private readonly FailureClassifier $classifier,
        private readonly Redactor $redactor,
        private readonly ApprovalService $approvals,
        private readonly ApprovalGuard $approvalGuard,
        private readonly Auditor $auditor,
        private readonly ConsentGate $consent,
        private readonly CredentialHealth $health,
    ) {}

    public function execute(ToolRequest $request, ?Approval $approval = null): ToolOutcome
    {
        // The correlation id travels from the caller into every log line, the tool, the connector
        // and the audit row. It is never regenerated here: a new id at each layer is the same as
        // having none.
        return Context::scope(function () use ($request, $approval) {
            return $this->run($request, $approval);
        }, ['correlation_id' => $request->correlationId, 'tool_key' => $request->toolKey]);
    }

    private function run(ToolRequest $request, ?Approval $approval = null): ToolOutcome
    {
        $descriptor = $this->descriptorFor($request->toolKey);
        $call = $this->recorder->open($request, $descriptor);

        // 1. Registered in code AND enabled at runtime. Config always wins; a database row can only
        //    ever narrow what the deployed code already contains.
        $availability = $this->availability->check($request->toolKey, $request->businessId);
        if (! $availability['available'] || $descriptor === null) {
            return $this->refuse($call, $request, OutcomeStatus::Unavailable, FailureClass::Unsupported,
                $availability['reason'] ?? "Tool [{$request->toolKey}] has no descriptor.", $descriptor,
                class: OutcomeClass::CapabilityUnavailable);
        }

        $tool = $this->registry->get($request->toolKey);

        // 2. Arguments, before anything else can act on them.
        $validation = $this->validator->validate($request->args, $tool->inputSchema());
        if (! $validation->valid) {
            return $this->refuse($call, $request, OutcomeStatus::Invalid, FailureClass::ValidationFailed,
                $validation->message(), $descriptor, class: OutcomeClass::InvalidArguments);
        }
        $args = $validation->normalised;
        $this->recorder->recordValidatedArgs($call, $args, $descriptor);
        // The values the recorder masked, kept so a connector that interpolates one into its own
        // exception message cannot write it into tool_calls.error. These are the CALLER's
        // arguments; the gateway still never sees a credential.
        $secrets = $this->redactor->secretsIn($args, $descriptor->redact);

        // 3. Scope. Resolved from the request context AND from the argument path the tool declared,
        //    because the Phase 1 scheduled jobs name their target in the payload (see ScopeResolver).
        $scope = $this->scopes->resolve($descriptor, $request, $args);
        if ($scope->missing) {
            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused, (string) $scope->problem, $descriptor,
                class: OutcomeClass::ScopeDenied);
        }
        if (($scopeProblem = $this->checkScope($descriptor, $request, $scope)) !== null) {
            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused, $scopeProblem, $descriptor,
                class: OutcomeClass::ScopeDenied);
        }
        // Now that the target is known, the row can say which business and store the call was for.
        // Before M2 an ERP poll recorded null for both, because its business lives in its payload.
        $this->recorder->recordScope($call, $scope);

        // 4/5. Permission and policy, in the engine. The permission intersection is step 4 of the
        //      engine's own order, so it lives there rather than being asked twice in two places
        //      that could one day disagree.
        $decision = $this->policy->decide($descriptor, $request, $request->identity, $args, $scope->businessId);
        $this->recorder->recordPolicy($call, $decision);

        if ($decision->outcome !== PolicyOutcome::Allow) {
            // An approval satisfies exactly ONE thing: the requirement for a human decision. It is
            // not a bypass. A deny after the approval was granted still denies, because the reasons
            // policy denies (a freeze, a revoked permission, a disabled tool) are all things that
            // SHOULD outrank yesterday's yes.
            $satisfied = $approval !== null && $decision->outcome === PolicyOutcome::RequireApproval;
            if (! $satisfied) {
                return $this->handleNonAllow($call, $request, $descriptor, $decision, $args, $scope);
            }
            $call->policy_reason = "{$decision->rule}: {$decision->reason} (satisfied by approval #{$approval->id})";
            $call->save();
        }

        // 5b. Consent, for any tool that declares a requirement (ADR-021, Phase 2.5 B).
        //
        //     ⛔ HERE, not inside each tool. A check written into each tool's body is a check that
        //     will eventually be forgotten in one of them, and the one it is forgotten in will be
        //     the newest channel. It sits below policy because a call policy already denies needs
        //     no consent lookup, and above execution because consent is not something to discover
        //     afterwards.
        $consent = $this->consent->check($descriptor, $request, $args, $scope->businessId);
        if (! $consent->permitted) {
            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused,
                'consent refused: '.$consent->reason, $descriptor, class: OutcomeClass::ConsentDenied);
        }

        // 6/7. Idempotency: a redelivered request returns its recorded result; an uncertain one is
        //      reconciled before anything is attempted again (ADR-011).
        if ($approval !== null) {
            $call->approval_id = $approval->id;
            $call->save();
        }

        $key = $this->keys->derive($request, $descriptor, $args);
        $this->recorder->recordIdempotencyKey($call, $key);

        $context = new ToolContext(
            idempotencyKey: $key,
            correlationId: $request->correlationId,
            // The RESOLVED business, so a tool that falls back to its context gets exactly the
            // target the gateway just enforced scope against.
            businessId: $scope->businessId,
            brandId: $request->brandId,
            jobRunId: $request->jobRunId,
            actorUserId: $request->identity->enablingUser()?->getKey(),
            attempt: $request->attempt,
            environment: (string) app()->environment(),
            permittedBusinessIds: $this->permittedBusinesses($request),
        );

        if (($replayed = $this->replay($call, $request, $descriptor, $tool, $context, $key, $decision)) !== null) {
            return $replayed;
        }

        // 8. Credentials, last, below every deterministic check. Ids are recorded; values are not
        //    handled here at all.
        $resolution = $this->credentials->resolve($descriptor, $request, $args, $scope);
        if (! $resolution->satisfied()) {
            // ⭐ ADR-043: an absent or rejected credential is an EXTERNAL class, never a refusal by
            //    HQ. The status stays `unavailable` for every reader that keyed on it; the class
            //    says which of the two it is, and the reason says what an operator does about it.
            return $this->refuse($call, $request, OutcomeStatus::Unavailable, FailureClass::Unsupported,
                $resolution->reason(), $descriptor, class: $resolution->outcomeClass());
        }

        // Only a call that will actually execute consumes quota. A refused one must not.
        $this->policy->recordPermittedCall($descriptor, $scope->businessId);

        // 9. Execute, with the declared credentials open for exactly the length of the call and
        //    cleared afterwards even if it throws. The connector asks the vault; it cannot look a
        //    credential up for itself (docs/50).
        $started = microtime(true);
        try {
            $result = $this->vault->open($resolution->found, fn () => $tool->execute($args, $context));
        } catch (Throwable $e) {
            $failure = $this->classifier->classify($e);
            Log::error('gateway: tool threw', [
                'tool' => $descriptor->key, 'failure' => $failure->value, 'tool_call_id' => $call->id, 'exception' => $e,
            ]);

            $status = $failure === FailureClass::Uncertain ? OutcomeStatus::Uncertain : OutcomeStatus::Failed;
            // Whose fault (ADR-043), read from the exception the connector threw: a 401 is the far
            // side rejecting the credential, a connection error is the network, a 5xx is the
            // provider. HQ permitted this call; none of these is HQ saying no.
            $class = OutcomeClass::fromThrowable($e);
            $this->recorder->complete($call, $status, error: get_class($e).': '.$e->getMessage(),
                failure: $failure, descriptor: $descriptor, secrets: $secrets, class: $class);
            $this->audit($request, $descriptor, $call, $status->value, $decision);
            // What the call taught HQ about the credentials it opened: a rejected token is recorded
            // on its row, so the next call is refused before a connector is asked again.
            $this->health->learnFromCall($resolution->found, $class, $e->getMessage(), 'tool_call#'.$call->id);

            return new ToolOutcome(
                status: $status, error: $e->getMessage(), failure: $failure, policy: $decision->outcome,
                policyReason: $decision->reason, toolCallId: $call->id,
                durationMs: (int) ((microtime(true) - $started) * 1000), class: $class,
            );
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);
        $outcome = ToolOutcome::fromToolResult($result, $decision->outcome, $durationMs);

        $this->recorder->complete($call, $outcome->status, output: $result->output, error: $result->error,
            failure: $outcome->failure, descriptor: $descriptor, secrets: $secrets, class: $outcome->class);
        $this->audit($request, $descriptor, $call, $outcome->status->value, $decision);
        // A success verifies the credentials it opened; a failure the tool classified as the far
        // side rejecting them condemns them; every other class leaves their record alone.
        $this->health->learnFromCall($resolution->found, $outcome->ok() ? null : $outcome->class, $result->error, 'tool_call#'.$call->id);

        return new ToolOutcome(
            status: $outcome->status, output: $result->output, error: $result->error, failure: $outcome->failure,
            policy: $decision->outcome, policyReason: $decision->reason, toolCallId: $call->id,
            durationMs: $durationMs, providerRef: $result->providerRef, class: $outcome->class,
        );
    }

    /**
     * A recorded result for the same key, or a reconciliation of an uncertain one.
     *
     * Replay applies when the caller supplied an explicit key (they are asserting the two calls are
     * one operation) or when the tool declares a replay window and the recorded row is inside it.
     * A derived key with no window never replays, so two genuine calls both run.
     */
    private function replay(
        ToolCall $call,
        ToolRequest $request,
        ToolDescriptor $descriptor,
        Tool $tool,
        ToolContext $context,
        string $key,
        PolicyDecision $decision,
    ): ?ToolOutcome {
        $explicit = $request->idempotencyKey !== null && $request->idempotencyKey !== '';
        $window = $descriptor->replayWithinSeconds;
        if (! $explicit && $window <= 0) {
            return null;
        }

        $ceiling = (int) config('hq.gateway.replay_ceiling_seconds', 86400);
        $since = CarbonImmutable::now()->subSeconds($explicit ? $ceiling : min($window, $ceiling));

        $prior = ToolCall::query()
            ->where('tool_key', $descriptor->key)
            ->where('idempotency_key', $key)
            ->where('id', '!=', $call->id)
            ->where('created_at', '>=', $since)
            ->whereIn('status', [OutcomeStatus::Succeeded->value, OutcomeStatus::Uncertain->value])
            ->orderByDesc('id')
            ->first();

        if ($prior === null) {
            return null;
        }

        if ($prior->status === OutcomeStatus::Succeeded->value) {
            $this->recorder->complete($call, OutcomeStatus::Replayed, output: $prior->output ?? [],
                descriptor: $descriptor, replayed: true);

            return new ToolOutcome(
                status: OutcomeStatus::Replayed, output: $prior->output ?? [], policy: $decision->outcome,
                policyReason: $decision->reason, toolCallId: $call->id, replayed: true,
            );
        }

        // Uncertain: the effect may have landed. Ask the tool to look it up before anything is
        // attempted again. A tool with nothing outside HQ to reconcile returns null, and an
        // idempotent tool is then safe to run.
        $recovered = $tool->reconcile($context);
        if ($recovered !== null) {
            $status = $recovered->status === ToolResult::STATUS_SUCCEEDED ? OutcomeStatus::Succeeded : OutcomeStatus::Uncertain;
            $this->recorder->complete($call, $status, output: $recovered->output, error: $recovered->error,
                failure: $status === OutcomeStatus::Uncertain ? FailureClass::Uncertain : null, descriptor: $descriptor);

            return new ToolOutcome(
                status: $status, output: $recovered->output, error: $recovered->error, policy: $decision->outcome,
                policyReason: $decision->reason, toolCallId: $call->id, providerRef: $recovered->providerRef,
            );
        }

        if (! $descriptor->idempotent) {
            return $this->refuse($call, $request, OutcomeStatus::Uncertain, FailureClass::Uncertain,
                "A previous call with this idempotency key is uncertain and [{$descriptor->key}] cannot reconcile it. A human must decide.",
                $descriptor, class: OutcomeClass::Uncertain);
        }

        return null; // idempotent and nothing to recover: executing again is safe
    }

    /**
     * Policy did not say allow.
     *
     * From M3, `require_approval` PARKS the call: an approval row is created holding the exact
     * validated payload and its hash, the tool call is marked awaiting approval, an approver is
     * notified, and the caller gets a pending outcome. Nothing external has happened, and no worker
     * is blocked waiting for a person: the run is checkpointed and resumable through the approval.
     *
     * Everything else is still a refusal.
     */
    private function handleNonAllow(
        ToolCall $call,
        ToolRequest $request,
        ToolDescriptor $descriptor,
        PolicyDecision $decision,
        array $args,
        ResolvedScope $scope,
    ): ToolOutcome {
        if ($decision->outcome !== PolicyOutcome::RequireApproval) {
            // A permission rule and a policy rule are both HQ saying no; the class tells them apart
            // for the screen and the grader, by the rule the engine recorded.
            $class = str_contains(mb_strtolower($decision->rule), 'permission') ? OutcomeClass::PermissionDenied : OutcomeClass::PolicyDenied;

            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused, $decision->reason, $descriptor, $decision, $class);
        }

        // An agent may PROPOSE a gated action; it may never be the thing that approves it. Parking
        // is therefore safe for every caller: the decision belongs to a human either way.
        $preview = $this->preview($descriptor, $request, $args, $scope);

        $approval = $this->approvals->park(
            request: $request,
            descriptor: $descriptor,
            args: $args,
            decision: $decision,
            call: $call,
            businessId: $scope->businessId,
            storeId: $scope->storeId,
            evidence: ['policy' => $decision->rule, 'policy_version' => $decision->policyVersion, 'requested_at' => now()->toIso8601String()],
            preview: $preview,
        );

        $call->forceFill([
            'status' => OutcomeStatus::AwaitingApproval->value,
            'error' => null,
        ])->save();

        $this->audit($request, $descriptor, $call, OutcomeStatus::AwaitingApproval->value, $decision, $decision->reason);

        return new ToolOutcome(
            status: OutcomeStatus::AwaitingApproval,
            output: ['approval_id' => $approval->id, 'approval_uuid' => $approval->uuid],
            error: $decision->reason.' It is waiting for a human decision in the Approval Center.',
            policy: $decision->outcome,
            policyReason: $decision->reason,
            toolCallId: $call->id,
            approvalId: $approval->id,
            class: OutcomeClass::ApprovalRequired,
        );
    }

    /**
     * A dry run, where the tool can offer one, so the approver sees what will happen rather than
     * only what was asked for.
     *
     * ⚠ A preview must never perform the external side effect. A tool that cannot preview says so,
     * and the approval card says so too: pretending a live execution is a preview would be the worst
     * possible way to fill this field.
     */
    private function preview(ToolDescriptor $descriptor, ToolRequest $request, array $args, ResolvedScope $scope): array
    {
        $tool = $this->registry->get($descriptor->key);
        if (! $tool instanceof PreviewableTool) {
            return ['available' => false, 'reason' => "[{$descriptor->key}] cannot describe what it would do without doing it."];
        }

        $context = new ToolContext(
            idempotencyKey: 'preview:'.$request->correlationId,
            correlationId: $request->correlationId,
            businessId: $scope->businessId,
            brandId: $request->brandId,
            actorUserId: $request->identity->enablingUser()?->getKey(),
            environment: (string) app()->environment(),
            permittedBusinessIds: $this->permittedBusinesses($request),
        );

        try {
            return ['available' => true, 'result' => $tool->preview($args, $context)];
        } catch (Throwable $e) {
            Log::warning('gateway: preview failed', ['tool' => $descriptor->key, 'error' => $e->getMessage()]);

            return ['available' => false, 'reason' => 'The preview could not be produced: '.get_class($e)];
        }
    }

    private function refuse(
        ToolCall $call,
        ToolRequest $request,
        OutcomeStatus $status,
        FailureClass $failure,
        string $reason,
        ?ToolDescriptor $descriptor,
        ?PolicyDecision $decision = null,
        ?OutcomeClass $class = null,
    ): ToolOutcome {
        // Every refusal names whose fault it is (ADR-043). A site that did not say is HQ's own
        // fault by construction: an unclassified refusal must never read as the far side failing.
        $class ??= OutcomeClass::InternalError;

        $this->recorder->complete($call, $status, error: $reason, failure: $failure, descriptor: $descriptor, class: $class);
        $this->audit($request, $descriptor, $call, $status->value, $decision, $reason);

        return new ToolOutcome(
            status: $status,
            error: $reason,
            failure: $failure,
            policy: $decision?->outcome ?? PolicyOutcome::Deny,
            policyReason: $decision?->reason ?? $reason,
            toolCallId: $call->id,
            class: $class,
        );
    }

    /**
     * Everything that depends on the RESOLVED target rather than on what the caller happened to put
     * in the request. A restricted human may not reach outside their businesses, and a tool says
     * which businesses and stores it supports.
     */
    private function checkScope(ToolDescriptor $descriptor, ToolRequest $request, ResolvedScope $scope): ?string
    {
        $user = $request->identity->enablingUser();
        if ($user !== null && ! $this->businessScope->allows($user, $scope->businessId)) {
            return "{$request->identity->label} is not scoped to business {$scope->businessId}.";
        }

        if (! $descriptor->supportsBusiness($scope->businessSlug)) {
            return "[{$descriptor->key}] does not support business [".($scope->businessSlug ?? 'none').'].';
        }

        if ($scope->storeId !== null && ! $descriptor->supportsStore($scope->storeSlug)) {
            return "[{$descriptor->key}] does not support store [".($scope->storeSlug ?? 'none').'].';
        }

        return null;
    }

    /**
     * The immutable record, kept distinct from the two others on purpose:
     *   tool_calls      what the gateway did, in full, including refusals
     *   connector_calls what left the building
     *   audit_logs      the append-only history a human reads
     *
     * Only R3 and above are audited. Auditing every read would bury the writes among them, and the
     * reads are already fully recorded in `tool_calls`, which is where a trace starts anyway.
     */
    private function audit(
        ToolRequest $request,
        ?ToolDescriptor $descriptor,
        ToolCall $call,
        string $outcome,
        ?PolicyDecision $decision,
        ?string $reason = null,
    ): void {
        $notable = $descriptor === null
            || $descriptor->riskTier->atLeast(RiskTier::R3)
            || in_array($outcome, [OutcomeStatus::Denied->value, OutcomeStatus::Invalid->value, OutcomeStatus::Unavailable->value], true);

        if (! $notable) {
            return;
        }

        $action = $descriptor?->auditAction ?? 'tool.unknown';
        $context = [
            'tool_call_id' => $call->id,
            'tool_key' => $request->toolKey,
            'caller' => $request->caller->value,
            'risk_tier' => $descriptor?->riskTier->value,
            'outcome' => $outcome,
            'policy' => $decision?->outcome->value,
            'policy_rule' => $decision?->rule,
            'business_id' => $request->businessId,
            'store_id' => $request->storeId,
        ];

        try {
            $identity = $request->identity;
            if ($identity->isAgent() && $identity->agent !== null) {
                $this->auditor->recordAgent($identity->agent->key, $action, 'tool_calls', after: $context, reason: $reason);
            } else {
                $this->auditor->record($action, 'tool_calls', after: $context, reason: $reason,
                    actorUserId: $identity->enablingUser()?->getKey());
            }
        } catch (Throwable $e) {
            // An audit failure must not swallow a completed tool call: the tool_calls row already
            // holds the truth, and losing it too would make a bad moment worse.
            Log::warning('gateway: audit write failed', ['tool' => $request->toolKey, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Which businesses this call's human may read, resolved once and handed to the tool (M5).
     *
     * A tool whose scope is `business` or `store` has already had its single target checked in
     * `checkScope`. This is for the `hq`-scoped tools that read ACROSS businesses, where there is
     * no single target to check and the filter can only be applied inside the query.
     *
     * ⚠ An unattended identity with no enabling human is NOT treated as unrestricted by accident:
     * it genuinely is group-wide, because `system` holds a fixed narrow permission list instead
     * (see ActingIdentity). The restriction being modelled here is a human's, and there is no human.
     */
    private function permittedBusinesses(ToolRequest $request): ?array
    {
        $user = $request->identity->enablingUser();

        return $user === null ? null : $this->businessScope->all($user);
    }

    private function descriptorFor(string $key): ?ToolDescriptor
    {
        if (! $this->registry->has($key)) {
            return null;
        }

        try {
            return $this->registry->get($key)->descriptor();
        } catch (Throwable $e) {
            Log::error('gateway: tool has no usable descriptor', ['tool' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Used by the registry screen and the completeness test: every registered tool's descriptor. */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->registry->keys() as $key) {
            $out[$key] = $this->registry->get($key)->descriptor();
        }

        return $out;
    }
}
