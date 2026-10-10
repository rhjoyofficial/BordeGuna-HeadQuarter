<?php

namespace App\FollowUps\Workflow;

use App\Workflow\Step;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowState;

/**
 * docs/113 section 7: the run an approved follow-up becomes. It sleeps until the due time and then
 * WAITS FOR A PERSON. There is no tool step: the run has no effect, reaches no customer, and the
 * structural tests (WorkflowStructureTest, FollowUpStructureTest) hold it to that.
 *
 * ⛔ When proactive follow-up is one day activated (a separate ruling and release), the contact becomes
 * a Tool Gateway tool step in a NEW version of this definition or a successor, never a direct send
 * (docs/113 section 8). Until then this file declares exactly two steps.
 */
final class FollowUpWorkflow extends WorkflowDefinition
{
    public const TYPE = 'followup.await_person';

    public const STEP_UNTIL_DUE = 'until_due';

    public const STEP_PERSON_DECISION = 'person_decision';

    public const SIGNAL_PERSON_DECIDED = 'followup.person_decided';

    /** Waiting for a person ends after this long; the follow-up row stays, visibly overdue (docs/113 Q9). */
    public const PERSON_WAIT_TIMEOUT_SECONDS = 180 * 86400;

    public function type(): string
    {
        return self::TYPE;
    }

    public function correlationKey(array $input): ?string
    {
        return isset($input['follow_up_uuid']) ? 'follow_up:'.$input['follow_up_uuid'] : null;
    }

    public function steps(): array
    {
        return [
            // The service computes the seconds at approval; a definition never reads the clock.
            Step::sleep(self::STEP_UNTIL_DUE, fn (WorkflowState $s) => max(0, (int) $s->input('seconds_until_due', 0))),
            Step::awaitSignal(self::STEP_PERSON_DECISION, self::SIGNAL_PERSON_DECIDED, self::PERSON_WAIT_TIMEOUT_SECONDS),
        ];
    }
}
