<?php

namespace App\Observe\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How many times a runtime guard refused an effectful call that the Tool Gateway WOULD have authorized, per agent,
 * per hour (docs/116, ruling 110 §2g).
 *
 * The two layers disagreeing is not a safety failure (the refusal stands; a guard can only remove), but it is
 * evidence that they have drifted apart: a guard narrower than policy, or policy wider than intended. One such
 * refusal is audit only (`agent_run.effectful_call_refused` with `gateway_would_authorize: true`, the durable
 * record); a pattern is what the rule `GuardGatewayDisagreement` alerts on, and this is what it counts.
 *
 * A counter in the cache, like `WebhookSignatureFailures`: the audit row is the record, this is the measure.
 * Counting can never break the run it counts: a cache failure is swallowed.
 */
final class GuardGatewayDisagreements
{
    private const PREFIX = 'hq:guard-gateway-disagreements:';

    /** The agents counted in an hour's bucket, so `recent()` can read back without knowing them in advance. */
    private const INDEX = 'agents';

    /** Kept for two days, longer than any window a rule reads by default. */
    private const TTL_SECONDS = 172800;

    public function record(string $agentKey, string $tool, ?CarbonImmutable $now = null): void
    {
        $hour = ($now ?? CarbonImmutable::now())->utc()->format('YmdH');

        try {
            $index = self::PREFIX.self::INDEX.':'.$hour;
            $agents = (array) Cache::get($index, []);
            if (! in_array($agentKey, $agents, true)) {
                $agents[] = $agentKey;
                Cache::put($index, $agents, self::TTL_SECONDS);
            }

            $key = self::PREFIX.$agentKey.':'.$hour;
            Cache::add($key, 0, self::TTL_SECONDS);
            Cache::increment($key);
        } catch (Throwable) {
            // The refusal stands and the audit row is written regardless; a missing count is the lesser loss.
        }
    }

    /**
     * @return array<string, int> disagreements per agent over the last `$hours` hours, this one included, sorted by
     *                            agent; agents with none are left out
     */
    public function recent(int $hours, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = [];

        for ($i = 0; $i < max(1, $hours); $i++) {
            $hour = $now->subHours($i)->utc()->format('YmdH');
            foreach ((array) Cache::get(self::PREFIX.self::INDEX.':'.$hour, []) as $agent) {
                $counts[$agent] = ($counts[$agent] ?? 0) + (int) Cache::get(self::PREFIX.$agent.':'.$hour, 0);
            }
        }

        ksort($counts);

        return array_filter($counts);
    }
}
