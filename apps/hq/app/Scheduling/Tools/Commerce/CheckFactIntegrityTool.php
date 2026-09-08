<?php

namespace App\Scheduling\Tools\Commerce;

use App\Facts\FactsIntegrityChecker;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Store;
use App\Scheduling\Tools\Tool;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolResult;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;

/**
 * Runs the order-fact arithmetic check on the durable scheduler and leaves the result on the record.
 *
 * Read-only and idempotent: it reads facts and writes one audit row saying what it found, so
 * running it twice costs two audit rows and changes no business data. Discrepancies are reported,
 * never repaired - see FactsIntegrityChecker for why that line is drawn there.
 *
 * A discrepancy is not a tool failure. The tool succeeded: it looked, and it found something. A
 * failed run would mean the check could not be performed at all, which is a different fact about
 * the world and deserves a different colour in the job history.
 */
final class CheckFactIntegrityTool implements Tool
{
    private const DEFAULT_HOURS = 168; // seven days: long enough to catch a slow-burning defect

    /** Enough to see the shape of a problem in the job history without turning the audit row into a data dump. */
    private const FINDINGS_IN_OUTPUT = 20;

    public function __construct(
        private readonly FactsIntegrityChecker $checker,
        private readonly Auditor $auditor,
    ) {}

    public function key(): string
    {
        return 'commerce.check_fact_integrity';
    }

    /**
     * Tier R1: it reads facts and writes one audit row saying what it found. Recording the call is
     * not an effect for the purpose of classification, or every tool in HQ would be R3 and the
     * scale would say nothing.
     *
     * This is the one Phase 2 tool with a replay window. Running the same arithmetic check over the
     * same window twice inside a minute is provably wasted work rather than merely similar work,
     * which is the only condition under which a derived key should replay at all.
     */
    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Check order fact integrity',
            purpose: 'Checks the arithmetic of stored order facts and reports discrepancies. Reports, never repairs.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.facts.read',
            auditAction: 'tool.commerce_check_fact_integrity',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: false,
            idempotent: true,
            idempotencyKeyFrom: ['store_slug', 'hours'],
            timeoutSeconds: 120,
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: 60,
        );
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'store' => ['type' => 'string'],
            'hours' => ['type' => 'integer'],
            'since' => ['type' => 'string'],
            'checked' => ['type' => 'integer'],
            'ok' => ['type' => 'integer'],
            'without_lines' => ['type' => 'integer'],
            'discrepancies' => ['type' => 'integer'],
            'findings' => ['type' => 'array'],
        ]];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8760],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $slug = $payload['store_slug'] ?? null;
        $store = null;
        if ($slug !== null) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                return ToolResult::failed("Unknown store [{$slug}]: omit store_slug to check every store.");
            }
        }

        $hours = (int) ($payload['hours'] ?? self::DEFAULT_HOURS);
        $since = CarbonImmutable::now('UTC')->subHours($hours);
        $result = $this->checker->check($store, $since);

        $summary = [
            'store' => $slug ?? 'all',
            'hours' => $hours,
            'since' => $since->toIso8601String(),
            'checked' => $result['checked'],
            'ok' => $result['ok'],
            'without_lines' => $result['without_lines'],
            'discrepancies' => $result['discrepancies'],
        ];

        $this->auditor->recordSystem(
            action: 'facts.integrity_checked',
            target: 'facts_orders',
            after: $summary + ['findings' => array_slice($result['findings'], 0, self::FINDINGS_IN_OUTPUT)],
            reason: $result['discrepancies'] === 0
                ? 'no discrepancies'
                : $result['discrepancies'].' discrepancy(ies) found; reported, not repaired',
        );

        return ToolResult::succeeded($summary + ['findings' => array_slice($result['findings'], 0, self::FINDINGS_IN_OUTPUT)]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // reading facts and writing an audit note is safe to repeat
    }
}
