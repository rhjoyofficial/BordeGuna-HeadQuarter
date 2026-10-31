<?php

namespace App\Skills;

use App\Ai\Pause\AiPause;
use App\Execution\EffectiveExecutionContext;
use App\Execution\ExecutionContextRefused;
use App\Execution\ExecutionContextResolver;
use App\Models\Agent;
use App\Models\AgentDelegation;
use App\Models\Skill;
use App\Support\Audit\Auditor;
use App\Support\Features\Features;
use Throwable;

/**
 * Delegation between agents (docs/89 Phase 9, docs/95 "a governed start-workflow, never a direct agent call",
 * ADR-047, docs/145-c).
 *
 * `delegate()` RESOLVES and RECORDS what a delegated piece of work could do: the target agent's own
 * intersection for the same accountable person, narrowed by the delegating run and by an ACTIVE skill.
 * It never starts a run and never calls the target agent: starting the work is a separate, human-approved
 * workflow step that does not exist yet (stub `DelegatedRunStarter`, unbound). Every request, refused or
 * not, leaves one append-only `agent_delegations` row and one audit line.
 *
 * ⛔ Off: the flag `agents.delegation` has no row, and Pause All AI refuses it too.
 */
final class Delegations
{
    public const FLAG = 'agents.delegation';

    public function __construct(
        private readonly ExecutionContextResolver $resolver,
        private readonly Features $features,
        private readonly AiPause $pause,
        private readonly Auditor $auditor,
    ) {}

    public function delegate(EffectiveExecutionContext $parent, Agent $target, Skill $skill, ?int $businessId = null, ?int $brandId = null, ?int $parentRunId = null): AgentDelegation
    {
        $refusal = match (true) {
            $this->pause->isPaused() => 'Pause All AI is on (or its state cannot be read), so nothing is delegated.',
            ! $this->features->enabled(self::FLAG, $businessId) => 'Delegation is off (flag agents.delegation).',
            $skill->status !== 'active' => "Skill [{$skill->key}] v{$skill->version} is {$skill->status}; only an active skill can be delegated.",
            $skill->business_id !== null && $businessId !== null && (int) $skill->business_id !== $businessId => "Skill [{$skill->key}] belongs to another business.",
            $skill->business_id !== null && $businessId === null => "Skill [{$skill->key}] is for one business; name it.",
            $target->key === $parent->agent->key => 'An agent does not delegate to itself.',
            default => null,
        };

        $effective = [];
        if ($refusal === null) {
            try {
                $context = $this->resolver->resolveDelegated($parent, $target, (array) $skill->required_tools, $businessId ?? ($skill->business_id !== null ? (int) $skill->business_id : null), $brandId);
                $effective = [
                    'business_ids' => $context->businessIds,
                    'brand_ids' => $context->brandIds,
                    'tools' => $context->toolKeys,
                    'permissions' => $context->permissions,
                    'requester_user_id' => (int) $context->requesterUser->getKey(),
                    'target_enabler_user_id' => (int) $context->enabler->getKey(),
                    'narrowed' => $context->narrowed,
                    'digest' => $context->digest(),
                ];
            } catch (ExecutionContextRefused $e) {
                $refusal = $e->getMessage();
            } catch (Throwable $e) {
                $refusal = 'The delegation could not be resolved: '.$e->getMessage();
            }
        }

        $row = AgentDelegation::query()->create([
            'parent_run_id' => $parentRunId,
            'from_agent_key' => (string) $parent->agent->key,
            'to_agent_key' => (string) $target->key,
            'skill_id' => $skill->getKey(),
            'business_id' => $businessId ?? $skill->business_id,
            'requester_user_id' => (int) $parent->requesterUser->getKey(),
            'status' => $refusal === null ? 'resolved' : 'refused',
            'refusal_reason' => $refusal,
            'parent_context_digest' => $parent->digest(),
            'effective' => $effective,
        ]);

        $this->auditor->recordAgent((string) $parent->agent->key, 'agents.delegation.'.$row->status, $row,
            after: ['to' => $target->key, 'skill' => $skill->key.'@'.$skill->version, 'business_id' => $row->business_id, 'tools' => $effective['tools'] ?? []],
            reason: $refusal);

        return $row;
    }
}
