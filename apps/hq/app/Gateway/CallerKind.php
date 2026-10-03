<?php

namespace App\Gateway;

/**
 * Which door a call came through.
 *
 * Distinct from the acting identity: a scheduler call runs AS the job's owning human, so
 * `caller_kind = scheduler` and `actor_type = human` are both true and both worth recording.
 * Losing either loses half the story of why something happened at 02:00.
 */
enum CallerKind: string
{
    case HumanUi = 'human_ui';
    case Scheduler = 'scheduler';
    case Event = 'event';
    case Agent = 'agent';
    case Api = 'api';
    case Console = 'console';
    case System = 'system';

    /** A step of a durable workflow run (docs/102), acting as the run's named requester. */
    case Workflow = 'workflow';
}
