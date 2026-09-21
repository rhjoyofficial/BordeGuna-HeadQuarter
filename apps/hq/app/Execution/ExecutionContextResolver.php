<?php

namespace App\Execution;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\Business;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place that turns (who asks, which agent, through which door, for which business and
 * brand) into the authority a run has (docs/80 points 3 and 4, ADR-047).
 *
 * Every AI entry point comes here: Ask HQ, the Shadow Probes the scheduler fires, the gate runner,
 * the support agent's path, the console. There is no second resolver and no bypass for a system
 * caller, because a bypass is the thing an attacker aims at (docs/48 E.3).
 *
 * ## The order, and why each step can only remove
 *
 *   1. the agent is active                                 (the documented rollback stays a rollback)
 *   2. an enabling user exists and is not disabled         (no enabling identity, no run)
 *   3. a requester is named, exists and is not disabled    (there is NO default requester)
 *   4. businesses = enabler AND requester AND agent row    (null only when nothing narrowed)
 *   5. the requested business lies inside that
 *   6. brands = agent row AND the brands of those businesses; the requested brand lies inside
 *   7. tools = the agent's list, whose permission BOTH people hold
 *   8. an agent that was granted tools and is left with none is refused, with the reason
 *
 * Runtime switch, policy, approval, consent, credentials and capability readiness need a call's
 * arguments. They are decided per call by the gateway, under the context resolved here.
 *
 * ⛔ Fail closed. Anything unresolvable is an `ExecutionContextRefused`, audited, and nothing runs.
 */
final class ExecutionContextResolver
{
    public function __construct(
        private readonly BusinessScope $businessScope,
        private readonly ToolRegistry $registry,
        private readonly Auditor $auditor,
    ) {}

    /** Resolve for a run. A refusal is audited before it is thrown. */
    public function resolve(Agent $agent, ?Requester $requester, string $entryPoint, ?int $businessId = null, ?int $brandId = null): EffectiveExecutionContext
    {
        try {
            return $this->intersect($agent, $requester, $entryPoint, $businessId, $brandId, requireActive: true);
        } catch (ExecutionContextRefused $e) {
            $this->auditRefusal($agent, $requester, $entryPoint, $businessId, $brandId, $e->getMessage());

            throw $e;
        }
    }

    /**
     * What a run WOULD have, for a screen that must say so before anybody asks (Ask HQ's agent
     * list). The same intersection; it writes nothing, and it does not ask whether the agent is
     * active, because "paused" is the run's answer and not a statement about this person.
     */
    public function preview(Agent $agent, Requester $requester, string $entryPoint, ?int $businessId = null, ?int $brandId = null): EffectiveExecutionContext
    {
        return $this->intersect($agent, $requester, $entryPoint, $businessId, $brandId, requireActive: false);
    }

    /** Store the context on the run and write its audit row. Called once, by the runner. */
    public function record(AgentRun $run, EffectiveExecutionContext $context): void
    {
        $record = $context->toAuditArray();

        $run->forceFill([
            'execution_context' => $record,
            'execution_context_digest' => $record['digest'],
        ])->save();

        try {
            $this->auditor->recordSystem('agent_run.context_resolved', $run, after: $record,
                reason: $context->narrowed === [] ? null : count($context->narrowed).' narrowing(s); see `narrowed`.',
                correlationId: (string) $run->correlation_id);
        } catch (Throwable $e) {
            // The run row already holds the context; an audit write that failed must not end a run.
            Log::warning('execution context: audit write failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Whether a context resolved earlier still stands, read fresh from the database before each
     * gateway call. Null when it does; otherwise why not.
     *
     * A run lasts seconds to minutes and holds the models it started with. Without this, a person
     * disabled or deleted mid-run, a person narrowed to fewer businesses mid-run, an agent paused
     * mid-run, or an agent handed to another enabling user mid-run would all keep working until the
     * run ended.
     */
    public function stillStands(EffectiveExecutionContext $context): ?string
    {
        $agent = Agent::query()->whereKey($context->agent->getKey())->first(['id', 'key', 'status', 'enabling_user_id']);
        if ($agent === null || ! $agent->isActive()) {
            return "Agent [{$context->agent->key}] is no longer active, so its run may call nothing further.";
        }
        if ((int) $agent->enabling_user_id !== (int) $context->enabler->getKey()) {
            return "Agent [{$context->agent->key}] no longer borrows the authority this run was resolved under, so the run may call nothing further.";
        }

        $ids = array_values(array_unique([(int) $context->enabler->getKey(), (int) $context->requesterUser->getKey()]));
        $alive = User::query()->whereIn('id', $ids)->get(['id', 'is_active'])
            ->reject(fn (User $u) => $u->is_active === false)->count();

        if ($alive !== count($ids)) {
            return "A person this run's authority rests on was disabled or removed, so the run may call nothing further.";
        }

        // docs/81 P0-2: business scope, read again from the table. The context stands only while
        // its business set still lies inside what each person may read NOW; a group-wide context
        // does not survive either person gaining rows. A context is never widened here: a row added
        // since shows at the next resolve.
        foreach ($ids as $id) {
            $now = $this->businessScope->all($id === (int) $context->enabler->getKey() ? $context->enabler : $context->requesterUser);
            if ($now === null) {
                continue;
            }
            if ($context->businessIds === null || array_diff($context->businessIds, $now) !== []) {
                return "A person this run's authority rests on was narrowed after this run was resolved, so the run may call nothing further.";
            }
        }

        return null;
    }

    private function intersect(Agent $agent, ?Requester $requester, string $entryPoint, ?int $businessId, ?int $brandId, bool $requireActive): EffectiveExecutionContext
    {
        // 1. The two sentences the runner always said, unchanged, because surfaces show them.
        if ($requireActive && ! $agent->isActive()) {
            throw new ExecutionContextRefused("Agent [{$agent->key}] is [{$agent->status}], so it will not run.");
        }

        // 2. The enabling identity, read fresh. An agent has no authority of its own.
        if ($agent->enabling_user_id === null) {
            throw new ExecutionContextRefused(
                "Agent [{$agent->key}] names no enabling user. An agent borrows a human's authority and has none of its own, "
                .'so one must be set before it can run.'
            );
        }
        $enabler = User::query()->find($agent->enabling_user_id);
        if ($enabler === null) {
            throw new ExecutionContextRefused("Agent [{$agent->key}] names an enabling user who no longer exists, so it has no authority to borrow.");
        }
        if ($enabler->is_active === false) {
            throw new ExecutionContextRefused("The enabling user of [{$agent->key}] (user #{$enabler->getKey()}) is disabled, so the agent has no authority to borrow.");
        }

        // 3. The requester. No default: an entry point that does not say who asks gets no run.
        if ($requester === null) {
            throw new ExecutionContextRefused("The request for [{$agent->key}] names no requester. Every run states who is asking; there is no default.");
        }
        if ($requester->asEnabled) {
            $requesterUser = $enabler;
        } else {
            if ($requester->userId === null) {
                throw new ExecutionContextRefused("Nobody accountable is recorded for this request ({$requester->basis}), so nothing runs in nobody's name.");
            }
            $requesterUser = (int) $requester->userId === (int) $enabler->getKey() ? $enabler : User::query()->find($requester->userId);
            if ($requesterUser === null) {
                throw new ExecutionContextRefused("The requester (user #{$requester->userId}, {$requester->basis}) no longer exists, and nobody takes their place.");
            }
            if ($requesterUser->is_active === false) {
                throw new ExecutionContextRefused("The requester (user #{$requesterUser->getKey()}) is disabled, so nothing runs in their name.");
            }
        }

        $narrowed = [];

        // 4. Businesses: every side that has a limit narrows; a side with none adds nothing.
        $businessIds = null;
        foreach ([
            'enabler' => $this->businessScope->all($enabler),
            'requester' => $requesterUser === $enabler ? null : $this->businessScope->all($requesterUser),
            'agent' => $this->scopeList($agent->business_scope, Business::class),
        ] as $side => $limit) {
            if ($limit === null) {
                continue;
            }
            $businessIds = $businessIds === null ? $limit : array_values(array_intersect($businessIds, $limit));
            $narrowed[] = ['term' => 'business', 'by' => $side, 'why' => "the {$side} is limited to business ids [".implode(', ', $limit).']'];
        }
        if ($businessIds !== null) {
            $businessIds = Business::query()->whereIn('id', $businessIds)->where('is_active', true)->orderBy('id')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($businessIds === []) {
                throw new ExecutionContextRefused(
                    "The requester, the enabling user of [{$agent->key}] and the agent's own scope share no business, so there is nothing this run could be about."
                );
            }
        }

        // 5. The business the run was opened for.
        if ($businessId !== null) {
            if (! Business::query()->whereKey($businessId)->exists()) {
                throw new ExecutionContextRefused("The run names business {$businessId}, which does not exist.");
            }
            if ($businessIds !== null && ! in_array($businessId, $businessIds, true)) {
                throw new ExecutionContextRefused("Business {$businessId} is outside what the requester and the enabling user of [{$agent->key}] may both read.");
            }
        }

        // 6. Brands. People hold no brand scope in HQ today (only business rows), so a person's
        //    brands are the brands of their businesses; the agent row and the request narrow.
        $brandIds = $this->scopeList($agent->brand_scope, Brand::class);
        if ($brandIds !== null) {
            $narrowed[] = ['term' => 'brand', 'by' => 'agent', 'why' => 'the agent is limited to brand ids ['.implode(', ', $brandIds).']'];
            $brandIds = Brand::query()->whereIn('id', $brandIds)
                ->when($businessIds !== null, fn ($q) => $q->whereIn('business_id', $businessIds))
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($brandIds === []) {
                throw new ExecutionContextRefused("The brand scope of [{$agent->key}] holds no brand of a business this run may read.");
            }
        }
        if ($brandId !== null) {
            $brand = Brand::query()->find($brandId);
            if ($brand === null) {
                throw new ExecutionContextRefused("The run names brand {$brandId}, which does not exist.");
            }
            if ($businessId !== null && (int) $brand->business_id !== $businessId) {
                throw new ExecutionContextRefused("The run names brand {$brandId}, which does not belong to business {$businessId}.");
            }
            if (($businessIds !== null && ! in_array((int) $brand->business_id, $businessIds, true))
                || ($brandIds !== null && ! in_array($brandId, $brandIds, true))) {
                throw new ExecutionContextRefused("The run names brand {$brandId}, which is outside the brand scope this run may use.");
            }
        }

        // 7. Tools: the agent's list, whose permission BOTH people hold.
        [$toolKeys, $permissions, $grantable] = $this->tools($agent, $enabler, $requesterUser, $narrowed);

        // 8. Granted tools and left with none: say so, rather than run an agent that can do nothing.
        if ($grantable > 0 && $toolKeys === []) {
            throw new ExecutionContextRefused(
                "The requester and the enabling user of [{$agent->key}] hold in common none of the permissions its tools need, "
                .'so none of the tools could run and the question is not put to it.'
            );
        }

        return new EffectiveExecutionContext(
            requester: $requester,
            requesterUser: $requesterUser,
            enabler: $enabler,
            agent: $agent,
            entryPoint: $entryPoint,
            environment: (string) app()->environment(),
            businessId: $businessId,
            brandId: $brandId,
            businessIds: $businessIds,
            brandIds: $brandIds,
            toolKeys: $toolKeys,
            permissions: $permissions,
            narrowed: $narrowed,
        );
    }

    /**
     * @param  list<array{term: string, by: string, why: string}>  $narrowed
     * @return array{0: list<string>, 1: list<string>, 2: int} tool keys, permission names, and how many tools the agent could have had
     */
    private function tools(Agent $agent, User $enabler, User $requesterUser, array &$narrowed): array
    {
        $keys = [];
        $permissions = [];
        $grantable = 0;

        foreach ((array) ($agent->allowed_tools ?? []) as $key) {
            $key = (string) $key;
            if (! $this->registry->has($key)) {
                continue;
            }
            try {
                $descriptor = $this->registry->get($key)->descriptor();
            } catch (Throwable) {
                continue;
            }
            // Whether an agent may EXECUTE the tool at all is not decided here: the catalogue leaves
            // such a tool out, and policy refuses it by name before it asks about permission.
            $grantable++;

            $permission = (string) $descriptor->permission;
            $missing = array_keys(array_filter([
                'enabler' => $permission === '' || ! $enabler->can($permission),
                'requester' => $permission === '' || ! $requesterUser->can($permission),
            ]));
            if ($missing !== []) {
                $narrowed[] = ['term' => 'tool_permission', 'by' => implode('+', $missing),
                    'why' => "[{$key}] needs [{$permission}], which the ".implode(' and the ', $missing).' does not hold'];

                continue;
            }

            $keys[] = $key;
            $permissions[] = $permission;
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return [$keys, $permissions, $grantable];
    }

    /**
     * An agent row's scope column. Null or `["*"]` does not narrow. A list narrows to the rows it
     * names, by id or by slug; an entry that names nothing is dropped; an EMPTY list is nothing at
     * all, the same fail-closed reading `allowed_tools` has.
     *
     * @param  class-string<Business|Brand>  $model
     * @return list<int>|null
     */
    private function scopeList(mixed $raw, string $model): ?array
    {
        if ($raw === null) {
            return null;
        }
        $raw = array_values((array) $raw);
        if (in_array('*', $raw, true)) {
            return null;
        }

        $ids = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $ids[] = (int) $entry;
            } elseif (is_string($entry) && $entry !== '' && ($id = $model::query()->where('slug', $entry)->value('id')) !== null) {
                $ids[] = (int) $id;
            }
        }
        $ids = $ids === [] ? [] : $model::query()->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique($ids));
    }

    private function auditRefusal(Agent $agent, ?Requester $requester, string $entryPoint, ?int $businessId, ?int $brandId, string $reason): void
    {
        try {
            $this->auditor->recordSystem('agent_run.context_refused', $agent, after: [
                'resolver_version' => EffectiveExecutionContext::VERSION,
                'agent_key' => $agent->key,
                'entry_point' => $entryPoint,
                'requester' => $requester === null ? null : [
                    'kind' => $requester->kind->value, 'user_id' => $requester->userId,
                    'as_enabled' => $requester->asEnabled, 'basis' => $requester->basis,
                ],
                'enabling_user_id' => $agent->enabling_user_id,
                'requested_business_id' => $businessId,
                'requested_brand_id' => $brandId,
            ], reason: $reason);
        } catch (Throwable $e) {
            Log::warning('execution context: refusal audit write failed', ['agent' => $agent->key, 'error' => $e->getMessage()]);
        }
    }
}
