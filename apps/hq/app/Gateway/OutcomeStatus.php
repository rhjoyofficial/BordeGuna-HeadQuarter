<?php

namespace App\Gateway;

/** What happened to one gateway call, as recorded on its tool_calls row. */
enum OutcomeStatus: string
{
    /** Recorded on entry; a row left in this state means the process died mid-call. */
    case Started = 'started';

    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** The effect may have landed and HQ could not confirm. Blocks automatic retry. */
    case Uncertain = 'uncertain';

    /** Permission or policy said no. Nothing was executed and no connector was touched. */
    case Denied = 'denied';

    /**
     * Policy requires a human, an approval is waiting, and the run is CHECKPOINTED rather than
     * blocked. Nothing external has happened and no worker is being held.
     */
    case AwaitingApproval = 'awaiting_approval';

    /** The arguments did not validate. Stopped at the gateway. */
    case Invalid = 'invalid';

    /** Not registered, disabled at runtime, or missing a credential it declared. */
    case Unavailable = 'unavailable';

    /** A recorded result for the same idempotency key was returned instead of executing again. */
    case Replayed = 'replayed';

    public function terminal(): bool
    {
        return $this !== self::Started && $this !== self::AwaitingApproval;
    }

    /** Parked, not finished: a decision is outstanding. */
    public function pending(): bool
    {
        return $this === self::AwaitingApproval;
    }

    public function ok(): bool
    {
        return $this === self::Succeeded || $this === self::Replayed;
    }
}
