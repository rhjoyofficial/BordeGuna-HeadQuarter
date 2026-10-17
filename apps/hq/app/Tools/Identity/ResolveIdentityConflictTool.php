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
use App\Identity\Conflicts\ConflictResolver;
use App\Identity\Conflicts\ReleaseEvidence;
use App\Models\CustomerIdentity;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerIdentityConflictResolution as Resolution;
use App\Models\User;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use InvalidArgumentException;

/**
 * `customers.identity_conflicts.resolve`: a person's evidence-backed resolution of one open identity conflict (docs/140,
 * ruling docs/131 item 3, docs/138 P3-W1). The ONLY way a conflict is resolved by a person; the reviewed repair
 * (`customers.quarantine.repair`) closes the conflicts its own splits cover. `GatewayCoverageStructureTest` pins
 * `->resolveConflict(` to this file.
 *
 * ⛔ Human-only by construction, three times over (the docs/115 and docs/126 pattern).
 *   1. `permanentlyHumanOnly: true`. Deciding who an identity belongs to is the act the identity incident forbids any
 *      lookup from doing silently; no approval, grant or configuration can ever let an agent run this (ADR-025).
 *   2. The actor is the human the gateway authenticated, never an argument; a call with no human behind it is refused.
 *   3. `permission: hq.customers.identity_conflicts.resolve`, held by owner and operator only (RolesAndPermissionsSeeder).
 *
 * Scope: the business slug names the business; the gateway refuses a business the person may not read before this
 * runs, and the resolver refuses a conflict, identity or claimant of any other business. R3: it writes HQ's own rows
 * only (the identity, the conflict, the append-only resolution, an audit row, restrictions re-recorded on a new owner),
 * reaches no provider, shop, ERP or customer, and moves no money. Reversible only by a person's new, recorded decision.
 */
final class ResolveIdentityConflictTool implements PreviewableTool, Tool
{
    public const KEY = 'customers.identity_conflicts.resolve';

    public const PERMISSION = 'hq.customers.identity_conflicts.resolve';

    public function __construct(private readonly ConflictResolver $resolver, private readonly ReleaseEvidence $release) {}

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
                'conflict_id' => ['type' => 'integer', 'minimum' => 1],
                'resolution' => ['type' => 'string', 'enum' => Resolution::CHOICES],
                'evidence' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
                'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
            ],
            'required' => ['business_slug', 'conflict_id', 'resolution', 'evidence', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string'],
            'replayed' => ['type' => 'boolean'],
            'resolution_id' => ['type' => 'integer'],
            'conflict_id' => ['type' => 'integer'],
            'resolution' => ['type' => 'string'],
            'owner_before_customer_id' => ['type' => 'integer'],
            'owner_after_customer_id' => ['type' => 'integer'],
            'released_from_quarantine' => ['type' => 'boolean'],
            'carried_restrictions' => ['type' => 'array'],
            'consent_review' => ['type' => 'array'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Resolve an identity conflict (person, with evidence)',
            purpose: 'Records, as the signed-in person, one resolution of an open identity conflict of one business: keep the owner, move the '
                .'identity to the claimant of the SAME business, or mark it not resolvable. Evidence and a reason are required; a held identity '
                .'is released only when the rows support it; never across a business, never a merge. Append-only resolution record and audit. '
                .'Writes HQ rows only.',
            riskTier: RiskTier::R3,
            permission: self::PERMISSION,
            auditAction: 'tool.customers_identity_conflicts_resolve',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: true,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'conflict_id', 'resolution'],
            timeoutSeconds: 30,
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
        $conflict = $context->businessId === null ? null : CustomerIdentityConflict::query()
            ->whereKey((int) ($payload['conflict_id'] ?? 0))->where('business_id', $context->businessId)->whereNull('resolved_at')->first();
        $identity = $conflict === null ? null : CustomerIdentity::query()->whereKey($conflict->identity_id)->first();

        return [
            'would_record' => $conflict === null ? 'nothing: no open conflict of this business has that number' : (string) ($payload['resolution'] ?? ''),
            'conflict_id' => $payload['conflict_id'] ?? null,
            'held_identity' => $identity?->quarantined_at !== null,
            'release_blockers' => $identity !== null && $identity->quarantined_at !== null && $context->businessId !== null
                ? $this->release->blockers($identity, $context->businessId) : [],
            'external_effect' => 'none: HQ rows only, an append-only resolution record and an audit row',
            'refused_when' => 'the conflict is of another business or already resolved another way, the claimant is not a standing customer of '
                .'this business, a held identity has no evidence for release, or no human is behind the call',
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $actor = $context->actorUserId === null ? null : User::query()->find($context->actorUserId);
        if ($actor === null) {
            return ToolResult::failed('A conflict is resolved by the signed-in person; this call carries no human actor, so nothing was changed.');
        }
        if ($context->businessId === null) {
            return ToolResult::failed('Name the business the conflict belongs to.');
        }

        try {
            $report = $this->resolver->resolveConflict(
                (int) $payload['conflict_id'], (int) $context->businessId, (string) $payload['resolution'], (string) $payload['evidence'], (string) $payload['reason'],
                $actor, $context->permittedBusinessIds, $context->correlationId,
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed('Resolution refused: '.$e->getMessage());
        }

        return ToolResult::succeeded($report);
    }

    /** Nothing outside HQ to look up: the resolution row makes a repeat a replay. */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
