<?php

namespace App\Gateway;

use App\Gateway\Credentials\CredentialResolver;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\PolicyGate;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Recording\IdempotencyKeyFactory;
use App\Gateway\Recording\ToolCallRecorder;
use App\Gateway\Redaction\Redactor;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\Business;
use App\Models\Store;
use App\Models\ToolCall;
use App\Scheduling\Tools\Tool;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolRegistry;
use App\Scheduling\Tools\ToolResult;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
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
 *   3. business scope + context      -> denied
 *   4. permission (intersection)     -> denied
 *   5. policy (deterministic)        -> allow | require_approval | deny
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
        private readonly PolicyGate $policy,
        private readonly BusinessScope $businessScope,
        private readonly CredentialResolver $credentials,
        private readonly IdempotencyKeyFactory $keys,
        private readonly ToolCallRecorder $recorder,
        private readonly FailureClassifier $classifier,
        private readonly Redactor $redactor,
        private readonly Auditor $auditor,
    ) {}

    public function execute(ToolRequest $request): ToolOutcome
    {
        // The correlation id travels from the caller into every log line, the tool, the connector
        // and the audit row. It is never regenerated here: a new id at each layer is the same as
        // having none.
        return Context::scope(function () use ($request) {
            return $this->run($request);
        }, ['correlation_id' => $request->correlationId, 'tool_key' => $request->toolKey]);
    }

    private function run(ToolRequest $request): ToolOutcome
    {
        $descriptor = $this->descriptorFor($request->toolKey);
        $call = $this->recorder->open($request, $descriptor);

        // 1. Registered in code AND enabled at runtime. Config always wins; a database row can only
        //    ever narrow what the deployed code already contains.
        $availability = $this->availability->check($request->toolKey, $request->businessId);
        if (! $availability['available'] || $descriptor === null) {
            return $this->refuse($call, $request, OutcomeStatus::Unavailable, FailureClass::Unsupported,
                $availability['reason'] ?? "Tool [{$request->toolKey}] has no descriptor.", $descriptor);
        }

        $tool = $this->registry->get($request->toolKey);

        // 2. Arguments, before anything else can act on them.
        $validation = $this->validator->validate($request->args, $tool->inputSchema());
        if (! $validation->valid) {
            return $this->refuse($call, $request, OutcomeStatus::Invalid, FailureClass::ValidationFailed,
                $validation->message(), $descriptor);
        }
        $args = $validation->normalised;
        $this->recorder->recordValidatedArgs($call, $args, $descriptor);
        // The values the recorder masked, kept so a connector that interpolates one into its own
        // exception message cannot write it into tool_calls.error. These are the CALLER's
        // arguments; the gateway still never sees a credential.
        $secrets = $this->redactor->secretsIn($args, $descriptor->redact);

        // 3. Scope. A business-scoped tool must name a business; a restricted human may not name
        //    one outside their rows; a tool that does not support this business says so.
        if (($scopeProblem = $this->checkScope($descriptor, $request)) !== null) {
            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused, $scopeProblem, $descriptor);
        }

        // 4. Permission: the intersection, evaluated in one place, with no fallback branch.
        if (! $request->identity->holds($descriptor->permission, $descriptor->key)) {
            return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused,
                "{$request->identity->label} does not hold [{$descriptor->permission}] for [{$descriptor->key}].", $descriptor);
        }

        // 5. Policy. Deterministic, takes no model output, cannot be talked to.
        $decision = $this->policy->decide($descriptor, $request, $request->identity);
        $this->recorder->recordPolicy($call, $decision);

        if ($decision->outcome !== PolicyOutcome::Allow) {
            return $this->handleNonAllow($call, $request, $descriptor, $decision);
        }

        // 6/7. Idempotency: a redelivered request returns its recorded result; an uncertain one is
        //      reconciled before anything is attempted again (ADR-011).
        $key = $this->keys->derive($request, $descriptor, $args);
        $this->recorder->recordIdempotencyKey($call, $key);

        $context = new ToolContext(
            idempotencyKey: $key,
            correlationId: $request->correlationId,
            businessId: $request->businessId,
            brandId: $request->brandId,
            jobRunId: $request->jobRunId,
            actorUserId: $request->identity->enablingUser()?->getKey(),
            attempt: $request->attempt,
            environment: (string) app()->environment(),
        );

        if (($replayed = $this->replay($call, $request, $descriptor, $tool, $context, $key, $decision)) !== null) {
            return $replayed;
        }

        // 8. Credentials, last, below every deterministic check. Ids are recorded; values are not
        //    handled here at all.
        $resolution = $this->credentials->resolve($descriptor, $request, $args);
        if (! $resolution->satisfied()) {
            return $this->refuse($call, $request, OutcomeStatus::Unavailable, FailureClass::Unsupported,
                $resolution->reason(), $descriptor);
        }

        // 9. Execute.
        $started = microtime(true);
        try {
            $result = $tool->execute($args, $context);
        } catch (Throwable $e) {
            $failure = $this->classifier->classify($e);
            Log::error('gateway: tool threw', [
                'tool' => $descriptor->key, 'failure' => $failure->value, 'tool_call_id' => $call->id, 'exception' => $e,
            ]);

            $status = $failure === FailureClass::Uncertain ? OutcomeStatus::Uncertain : OutcomeStatus::Failed;
            $this->recorder->complete($call, $status, error: get_class($e).': '.$e->getMessage(),
                failure: $failure, descriptor: $descriptor, secrets: $secrets);
            $this->audit($request, $descriptor, $call, $status->value, $decision);

            return new ToolOutcome(
                status: $status, error: $e->getMessage(), failure: $failure, policy: $decision->outcome,
                policyReason: $decision->reason, toolCallId: $call->id,
                durationMs: (int) ((microtime(true) - $started) * 1000),
            );
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);
        $outcome = ToolOutcome::fromToolResult($result, $decision->outcome, $durationMs);

        $this->recorder->complete($call, $outcome->status, output: $result->output, error: $result->error,
            failure: $outcome->failure, descriptor: $descriptor, secrets: $secrets);
        $this->audit($request, $descriptor, $call, $outcome->status->value, $decision);

        return new ToolOutcome(
            status: $outcome->status, output: $result->output, error: $result->error, failure: $outcome->failure,
            policy: $decision->outcome, policyReason: $decision->reason, toolCallId: $call->id,
            durationMs: $durationMs, providerRef: $result->providerRef,
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
                $descriptor);
        }

        return null; // idempotent and nothing to recover: executing again is safe
    }

    private function handleNonAllow(ToolCall $call, ToolRequest $request, ToolDescriptor $descriptor, PolicyDecision $decision): ToolOutcome
    {
        // The Approval Center is M3. Until it exists, an approval requirement is a refusal with a
        // reason that says so, never a row that waits for a decision nobody can make. Fail closed,
        // and be honest about why.
        $reason = $decision->outcome === PolicyOutcome::RequireApproval
            ? $decision->reason.' The Approval Center arrives in M3, so this call is refused rather than queued.'
            : $decision->reason;

        return $this->refuse($call, $request, OutcomeStatus::Denied, FailureClass::Refused, $reason, $descriptor, $decision);
    }

    private function refuse(
        ToolCall $call,
        ToolRequest $request,
        OutcomeStatus $status,
        FailureClass $failure,
        string $reason,
        ?ToolDescriptor $descriptor,
        ?PolicyDecision $decision = null,
    ): ToolOutcome {
        $this->recorder->complete($call, $status, error: $reason, failure: $failure, descriptor: $descriptor);
        $this->audit($request, $descriptor, $call, $status->value, $decision, $reason);

        return new ToolOutcome(
            status: $status,
            error: $reason,
            failure: $failure,
            policy: $decision?->outcome ?? PolicyOutcome::Deny,
            policyReason: $decision?->reason ?? $reason,
            toolCallId: $call->id,
        );
    }

    private function checkScope(ToolDescriptor $descriptor, ToolRequest $request): ?string
    {
        if ($descriptor->requiresBusinessContext() && $request->businessId === null && $request->storeId === null) {
            return "[{$descriptor->key}] is scoped to a {$descriptor->scope->value} and the call named neither a business nor a store.";
        }

        $user = $request->identity->enablingUser();
        if ($user !== null && ! $this->businessScope->allows($user, $request->businessId)) {
            return "{$request->identity->label} is not scoped to business {$request->businessId}.";
        }

        $businessSlug = $request->businessId ? Business::find($request->businessId)?->slug : null;
        if (! $descriptor->supportsBusiness($businessSlug)) {
            return "[{$descriptor->key}] does not support business [".($businessSlug ?? 'none').'].';
        }

        $storeSlug = $request->storeId ? Store::find($request->storeId)?->slug : null;
        if ($request->storeId !== null && ! $descriptor->supportsStore($storeSlug)) {
            return "[{$descriptor->key}] does not support store [".($storeSlug ?? 'none').'].';
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
