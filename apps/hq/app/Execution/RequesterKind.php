<?php

namespace App\Execution;

/**
 * What kind of thing asked for an agent run (docs/80 point 3, ADR-047).
 *
 * Said honestly, because the four are accountable in different ways. None of them carries authority
 * of its own: a kind is a label on the record, and the authority always comes from a person.
 */
enum RequesterKind: string
{
    /** A signed-in person asked: Ask HQ, or the console naming somebody with `--as`. */
    case Human = 'human';

    /** The scheduler or the system, for the accountable person recorded on the job or the period. */
    case OnBehalfOf = 'on_behalf_of';

    /** An evaluation. Nobody asked; the agent is measured exactly as it was enabled. */
    case GateRunner = 'gate_runner';

    /** A shell on the host, naming nobody. */
    case Console = 'console';
}
