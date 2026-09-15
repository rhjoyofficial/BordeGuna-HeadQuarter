<?php

namespace App\Tools\Erp;

use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Erp\ErpRegistry;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Runs one of the ERP's own reports, from a whitelist (docs/48 D.6).
 *
 * ## The whitelist is the whole point
 *
 * ⛔ **Report keys are never free-form.** The ERP's report runner takes a key and a parameter bag,
 * and handing a model an open key space would be handing it an interface whose reach nobody here
 * has enumerated - a soft version of the SQL tool that must never exist. The permitted keys live in
 * `config('hq.erp.report_whitelist')`, so widening the surface is a deploy somebody reviews.
 *
 * ⚠ **A key the ERP offers is not automatically a key HQ may run.** The tool checks the whitelist
 * FIRST and the ERP's own catalogue second, so a report appearing at the ERP does not silently
 * become available here the day it is added there.
 *
 * ⚠ V.9 (8 Sep 2026): the HQ token deliberately does NOT hold the purchasing report permission. A
 * purchasing report therefore comes back as a permission refusal from the ERP, and that refusal is
 * reported as an answer - "not available: permission not granted" - rather than retried or hidden.
 */
final class RunErpReportTool implements Tool
{
    private const MAX_ROWS = 500;

    public function __construct(private readonly ErpRegistry $registry) {}

    public function key(): string
    {
        return 'erp.run_report';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Run an ERP report',
            purpose: 'Runs one of a fixed list of ERP reports for a business and returns its rows. Read-only; the report key is whitelisted.',
            riskTier: RiskTier::R2,
            permission: 'hq.tools.erp.read',
            auditAction: 'tool.erp_run_report',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            scopeFrom: 'business:args.business_slug',
            cardinality: ToolCardinality::OnePerBusiness,
            credentials: [
                new CredentialRequirement(ErpRegistry::PROVIDER, 'args.business_slug|business.slug'),
            ],
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'report_key'],
            timeoutSeconds: 90,
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: 60,
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'report_key' => ['type' => 'string', 'maxLength' => 64],
                'parameters' => ['type' => 'object'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS],
            ],
            'required' => ['report_key'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'report_key' => ['type' => 'string'],
            'rows' => ['type' => 'array'],
            'row_count' => ['type' => 'integer'],
        ]];
    }

    /** @return list<string> */
    public static function whitelist(): array
    {
        $keys = config('hq.erp.report_whitelist', []);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $reportKey = (string) ($payload['report_key'] ?? '');
        $whitelist = self::whitelist();

        if (! in_array($reportKey, $whitelist, true)) {
            // Name what IS permitted. A refusal that does not say what would have worked turns into
            // a model guessing keys, which is the loop the bounds exist to stop.
            return ToolResult::failed(
                "[{$reportKey}] is not a report HQ may run. Permitted: "
                .($whitelist === [] ? 'none are configured.' : implode(', ', $whitelist).'.')
            );
        }

        $businessId = $context->businessId;
        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id') ?? $businessId;
        }

        $business = $businessId === null ? null : Business::find($businessId);
        if ($business === null) {
            return ToolResult::failed('Name the business whose report you want.');
        }

        try {
            $rows = $this->registry->for($business)->runReport(
                $reportKey,
                (array) ($payload['parameters'] ?? []),
                min((int) ($payload['limit'] ?? 200), self::MAX_ROWS),
            );
        } catch (Unsupported $e) {
            return ToolResult::failedBy($e);
        } catch (Unavailable $e) {
            throw $e;
        }

        $rows = is_array($rows) ? $rows : [];

        return ToolResult::succeeded([
            'business' => $business->slug,
            'report_key' => $reportKey,
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'row_count' => count($rows),
            'read_live_at' => CarbonImmutable::now($timezone)->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
