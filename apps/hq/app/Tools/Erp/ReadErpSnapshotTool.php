<?php

namespace App\Tools\Erp;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Reads the ERP snapshot HQ already holds, without going to the ERP (docs/48 D.6, ADR-007).
 *
 * ⭐ **Why this exists when `erp.poll_overview` does.** The poll tools are classified **R3, Write**,
 * and correctly so: they call the ERP and then REPLACE a `facts_erp_snapshots` row. That makes them
 * the wrong thing for an agent to reach for when it simply wants to know what the ERP said, and it
 * is the reason neither poll tool is agent-executable. An agent answering a question should not be
 * able to mutate a read model as a side effect of curiosity.
 *
 * So the agents read the snapshot; the scheduler refreshes it. The split also means an agent cannot
 * put the ERP on the critical path of a question, which is the failure `docs/48` V.10 was weighing.
 *
 * ⚠ Every result carries `as_of` and `age_minutes`. A snapshot with no age is a fact with no date,
 * and `docs/45`'s whole lesson was that HQ had been reading a host that answered 404 for days with
 * nothing anywhere saying so.
 */
final class ReadErpSnapshotTool implements Tool
{
    private const KINDS = ['overview', 'low_stock'];

    public function key(): string
    {
        return 'erp.read_snapshot';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Read ERP snapshot',
            purpose: 'Returns the ERP overview or low-stock snapshot HQ holds for ONE business, named by business_slug, with its age. '
                .'Reads HQ only; never calls the ERP. Only businesses connected to the ERP hold a snapshot.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.erp.read',
            auditAction: 'tool.erp_read_snapshot',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            scopeFrom: 'business:args.business_slug',
            cardinality: ToolCardinality::OnePerBusiness,
            idempotent: true,
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'kind' => ['type' => 'string', 'enum' => self::KINDS],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'as_of' => ['type' => 'string'],
            'age_minutes' => ['type' => 'integer'],
            'payload' => ['type' => 'object'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');

        $businessId = $context->businessId;
        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id') ?? $businessId;
        }

        if ($businessId === null) {
            return ToolResult::failed('Name the business whose snapshot you want.');
        }

        $business = Business::find($businessId);
        $kind = (string) ($payload['kind'] ?? 'overview');

        $snapshot = FactsErpSnapshot::query()
            ->where('business_id', $businessId)
            ->where('kind', $kind)
            ->latest('polled_at')
            ->first();

        if ($snapshot === null) {
            // Not a failure: HQ genuinely holds nothing, and saying so is the correct answer. A
            // failure would invite a retry that can only produce the same absence.
            return ToolResult::succeeded([
                'business' => $business?->slug,
                'kind' => $kind,
                'as_of' => null,
                'age_minutes' => null,
                'payload' => null,
                'note' => "HQ holds no [{$kind}] snapshot for this business. It has never been polled, or the poll has never succeeded. "
                    .'Treat anything that would rest on it as unavailable rather than as zero.',
            ]);
        }

        $asOf = $snapshot->as_of ?? $snapshot->polled_at;
        $moment = $asOf === null ? null : CarbonImmutable::parse($asOf)->setTimezone($timezone);

        return ToolResult::succeeded([
            'business' => $business?->slug,
            'kind' => $kind,
            'as_of' => $moment?->toIso8601String(),
            'polled_at' => $snapshot->polled_at?->setTimezone($timezone)->toIso8601String(),
            'age_minutes' => $moment === null ? null : (int) $moment->diffInMinutes(CarbonImmutable::now($timezone)),
            'payload' => $snapshot->payload,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
