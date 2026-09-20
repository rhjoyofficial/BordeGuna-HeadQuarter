<?php

namespace App\Briefing;

use App\Models\Business;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The Executive Briefing capability, as an explicit contract (docs/80 point 2).
 *
 * ⭐ **This is the durable replacement for teaching the runtime to recognise phrases.** "What needs
 * my attention", "how did the group do", "how current is the data": an operator's words may be
 * mapped to this capability by whatever surface takes them, but nothing here reads language. The
 * capability is a name, a fixed task, a typed input and a typed evidence surface, and its semantics
 * are the same whoever asked and however they put it.
 *
 * - **The trusted task is fixed text owned by HQ.** It is a constant in code, reviewed like code. No
 *   caller supplies it, extends it or sees it as an argument.
 * - **The input is a scope and a date**, validated here. There is no free text in it.
 * - **The evidence surface is exactly the bundle** (`EvidenceBundle::VERSION`). A narrator is handed
 *   the bundle and the task and nothing else, and what it writes is checked against the bundle.
 * - **Narration is behind a flag that does not exist as a row**, which in this project means off
 *   (`Features::enabled` answers false for a key with no row).
 *
 * It is registered in `config('hq.briefing.contract')`. The Phase 2.5 Capability Registry
 * (`App\Platform`) answers a different question, what a connected external account can do right now
 * (ADR-022), and is not where a capability of HQ itself belongs.
 */
final class ExecutiveBriefingContract
{
    public const CAPABILITY = 'executive.briefing';

    public const VERSION = '1.0.0';

    public const TOOL = 'briefing.compose';

    public const NARRATION_FLAG = 'briefing.narration';

    /** Fixed, and HQ's own. A change here is a change to the capability and bumps the version. */
    public const TRUSTED_TASK = 'Narrate the Executive standard briefing for the stated date and scope from the attached evidence bundle. '
        .'Say first which sources are stale or missing. Quote only values that are in the bundle, as written there. '
        .'Mark every reading of the facts as an inference and every suggestion as a recommendation that leaves the decision to the operator.';

    /** Every section a bundle must carry, present even when it has nothing to say (docs/48 M.2). */
    public const SECTIONS = [
        'yesterday' => 'Yesterday',
        'scheduled_today' => 'Scheduled today',
        'anomalies' => 'Anomalies',
        'inventory' => 'Inventory signals',
        'approvals' => 'Pending approvals',
        'decisions' => 'Waiting on a decision',
        'marketing' => 'Marketing',
        'finance' => 'Finance',
    ];

    /** The sections whose source does not exist yet, and why. */
    public const NOT_CONNECTED = [
        'marketing' => 'Not connected yet: HQ reads no ads, content or publishing platform until Phase 5, so it has no marketing figures to report.',
        'finance' => 'Not connected yet: HQ reads no ledger, receivable or cash figures from the ERP until Phase 5, so it has no financial figures to report.',
    ];

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'date' => ['type' => 'string', 'maxLength' => 10],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The typed request for validated arguments.
     *
     * @param  list<int>|null  $permittedBusinessIds
     * @param  array<string, bool>  $mayRead
     *
     * @throws InvalidArgumentException when the date or the business cannot be what the caller says
     */
    public function request(array $args, CarbonImmutable $now, ?array $permittedBusinessIds = null, array $mayRead = []): BriefingRequest
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $today = $now->setTimezone($timezone)->startOfDay();
        $date = $today;

        if (($raw = $args['date'] ?? null) !== null) {
            if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
                throw new InvalidArgumentException('The briefing date is a calendar date written YYYY-MM-DD.');
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
            } catch (Throwable) {
                $date = false;
            }
            if ($date === false || $date === null || $date->toDateString() !== $raw) {
                throw new InvalidArgumentException("[{$raw}] is not a calendar date.");
            }
            if ($date->greaterThan($today)) {
                throw new InvalidArgumentException('A briefing cannot be composed for a day that has not begun.');
            }
        }

        $businessId = null;
        $slug = $args['business_slug'] ?? null;
        if ($slug !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                throw new InvalidArgumentException("HQ has no business with the slug [{$slug}].");
            }
            if ($permittedBusinessIds !== null && ! in_array((int) $businessId, $permittedBusinessIds, true)) {
                throw new InvalidArgumentException("The caller may not read the business [{$slug}].");
            }
        }

        return new BriefingRequest($date, $timezone, $now, $businessId === null ? null : (int) $businessId, $slug, $permittedBusinessIds, $mayRead);
    }

    /** The contract as data, for a registry screen or a record. */
    public function toArray(): array
    {
        return [
            'capability' => self::CAPABILITY,
            'version' => self::VERSION,
            'tool' => self::TOOL,
            'trusted_task' => self::TRUSTED_TASK,
            'trusted_task_digest' => hash('sha256', self::TRUSTED_TASK),
            'input' => $this->inputSchema(),
            'evidence_surface' => EvidenceBundle::VERSION,
            'sections' => array_keys(self::SECTIONS),
            'collector_kinds' => [EvidenceKind::Fact->value, EvidenceKind::Metric->value],
            'narrator_kinds' => [EvidenceKind::Inference->value, EvidenceKind::Recommendation->value],
            'narration_flag' => self::NARRATION_FLAG,
        ];
    }
}
