<?php

namespace App\Observe\Alerts;

use Carbon\CarbonImmutable;

/**
 * A condition HQ checks every five minutes (docs/48 N.5, docs/78 B.2).
 *
 * A rule only reads. It never repairs, retries or switches anything; the alert is the whole of its
 * effect, and the engine owns that.
 */
interface AlertRule
{
    /** Stable, and the prefix of every fingerprint the rule makes. */
    public function key(): string;

    /**
     * What already pages this condition (`hq-monitor.sh`, `model runtime`, `approvals sweep`), or null
     * when HQ's engine sends the message. A paged-elsewhere alert is recorded and shown, not sent twice.
     */
    public function pagedBy(): ?string;

    /**
     * The conditions true now. An empty list means all clear. Null means the rule could not tell (a
     * host file it cannot read, a service it cannot ask), and the engine leaves its open alerts alone:
     * not knowing is not the same as being fine.
     *
     * @return list<AlertCondition>|null
     */
    public function evaluate(CarbonImmutable $now): ?array;
}
