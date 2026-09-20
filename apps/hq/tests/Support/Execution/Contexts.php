<?php

namespace Tests\Support\Execution;

use App\Execution\EffectiveExecutionContext;
use App\Execution\ExecutionContextResolver;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Models\Agent;
use App\Models\User;

/**
 * Test access to the Effective Execution Context (ADR-047).
 *
 * ⛔ Always through the real resolver. A test that assembled a context by hand would be testing
 * authority nobody resolved, which is the very thing the source-reading test forbids in `app/`.
 */
final class Contexts
{
    /** The requester of a test run that has no separate person: the agent runs as it was enabled. */
    public static function asEnabled(): Requester
    {
        return Requester::asEnabled(RequesterKind::Console, 'test: nobody asked; the agent runs as enabled');
    }

    /** The context a run of this agent gets when nobody but its enabling user is involved. */
    public static function for(Agent $agent, ?int $businessId = null, ?int $brandId = null, ?User $requester = null): EffectiveExecutionContext
    {
        return app(ExecutionContextResolver::class)->resolve(
            $agent,
            $requester === null ? self::asEnabled() : Requester::human($requester, 'test'),
            'test',
            $businessId,
            $brandId,
        );
    }
}
