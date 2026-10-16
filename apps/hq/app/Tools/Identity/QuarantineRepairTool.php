<?php

namespace App\Tools\Identity;

use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Identity\Repair\RepairApplier;
use App\Identity\Repair\RepairPlanner;
use App\Models\User;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use InvalidArgumentException;

/**
 * `customers.quarantine.repair`: the evidence-backed repair of rows that hang under a customer of another
 * business (docs/126, ruling docs/120 A3 and item 36). The ONLY way a repair is applied: the console command is a
 * dry run and the controller only asks the gateway for this tool (`GatewayCoverageStructureTest` pins
 * `->applyRepair(` to this file).
 *
 * ⛔ Human-only by construction, three times over (the docs/115 pattern).
 *   1. `permanentlyHumanOnly: true`. Changing who an identity, an order or a thread belongs to is the act the
 *      incident forbids any lookup from doing silently; only a person's explicit, evidence-backed authorization may
 *      do it (A2 "only an explicitly authorized, evidence-backed repair may change ownership"). No approval, grant
 *      or configuration can ever let an agent run this (ADR-025).
 *   2. The actor is the human the gateway authenticated, never an argument; a call with no human behind it is refused here.
 *   3. `permission: hq.customers.quarantine.repair`, held by owner and operator only (RolesAndPermissionsSeeder).
 *
 * What it needs: the batch key the dry run printed (so the person applies exactly what they reviewed; changed data
 * yields another key and is refused), the business the batch belongs to (the gateway resolves and enforces the
 * caller's scope on it before execution), and a reason. The applier then refuses, per split, a concurrent change,
 * a missing `needs_repair` review (docs/117 §5) or a business the person may not read. R3: it writes HQ's own rows
 * only (customers, identities, order copies, threads, memories, conflicts, the lineage), reaches no provider, no
 * shop, no ERP and no customer, and moves no money. Reversible: every move is recorded in `customer_merge_reversals`.
 */
final class QuarantineRepairTool implements PreviewableTool, Tool
{
    public const KEY = 'customers.quarantine.repair';

    public const PERMISSION = 'hq.customers.quarantine.repair';

    public function __construct(private readonly RepairPlanner $planner, private readonly RepairApplier $applier) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'repair_key' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[0-9a-f]{64}$'],
                'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
            ],
            'required' => ['business_slug', 'repair_key', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string'],
            'repair_id' => ['type' => 'integer'],
            'key' => ['type' => 'string'],
            'replayed' => ['type' => 'boolean'],
            'splits' => ['type' => 'array'],
            'ambiguous_flagged' => ['type' => 'integer'],
            'verification' => ['type' => ['object', 'null']],
            'recount' => ['type' => ['object', 'null']],
            'human_lists' => ['type' => 'object'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Repair cross-business customer rows (reviewed batch)',
            purpose: 'Applies, as the signed-in person, one reviewed repair batch: rows of this business that hang under a customer of '
                .'another business go to a customer of this business (found by their own keys or created), store-scoped identities held '
                .'by the migration come back live under that customer, conflicts close, lineage is recorded in customer_merge_reversals '
                .'and the original merge row is untouched. Dry run is the console command; this is the mutation. Refuses a changed '
                .'batch, an unreviewed split and an ambiguous row (quarantined instead). Writes HQ rows only.',
            riskTier: RiskTier::R3,
            permission: self::PERMISSION,
            auditAction: 'tool.customers_quarantine_repair',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: true,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'repair_key'],
            timeoutSeconds: 120,
            outputSchema: $this->outputSchema(),
            channel: Channel::Internal,
            monetaryImpact: MonetaryImpact::None,
            customerFacing: false,
            reversibility: Reversibility::Reversible,
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
        );
    }

    public function preview(array $payload, ToolContext $context): array
    {
        $batch = $context->businessId === null ? null : $this->planner->plan()->batchForBusiness($context->businessId);
        $matches = $batch !== null && $batch['key'] === ($payload['repair_key'] ?? null);

        return [
            'would_apply' => $matches ? count($batch['splits']).' split(s) of the reviewed batch' : 'nothing: the key names no current batch of this business',
            'key' => $payload['repair_key'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'external_effect' => 'none: HQ rows only, lineage in customer_merge_reversals, the original merge row untouched',
            'refused_when' => 'the data changed since the dry run, a split has no needs_repair review or a disputed one, '
                .'the person may not read both businesses, or no human is behind the call',
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $actor = $context->actorUserId === null ? null : User::query()->find($context->actorUserId);
        if ($actor === null) {
            return ToolResult::failed('A repair needs the signed-in person behind the call; this call carries no human actor, so nothing was changed.');
        }
        if ($context->businessId === null) {
            return ToolResult::failed('Name the business the batch belongs to.');
        }

        try {
            $report = $this->applier->applyRepair(
                (string) $payload['repair_key'], (int) $context->businessId, $actor, (string) $payload['reason'], $context->correlationId, $context->permittedBusinessIds,
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed('Repair refused: '.$e->getMessage());
        }

        return ToolResult::succeeded([
            'status' => (string) $report['status'],
            'repair_id' => (int) $report['repair_id'],
            'key' => (string) $report['key'],
            'replayed' => (bool) $report['replayed'],
            'splits' => $report['splits'],
            'ambiguous_flagged' => (int) ($report['ambiguous_flagged'] ?? 0),
            'verification' => $report['verification'] ?? null,
            'recount' => $report['recount'] ?? null,
            'human_lists' => $report['human_lists'] ?? ['consent_rerecord' => [], 'memories_unscoped' => []],
        ]);
    }

    /** Nothing outside HQ to look up: the batch row and the reversal rows make a repeat a replay. */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
