<?php

namespace App\Observe\Alerts\Rules;

use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * A capability HQ supports cannot serve a call for a reason a person must act on (ADR-043, docs/77
 * E.1): no credential, a rejected one, an account with no credit, a missing permission, a switch off.
 *
 * Read from what HQ already knows; this never probes a provider. A credit block is reported as a
 * credit block, never as a bad key.
 */
final class ReadinessIncident implements AlertRule
{
    private const WHAT_TO_DO = [
        ReadinessRow::CREDENTIAL_MISSING => 'Store one on HQ > Credentials.',
        ReadinessRow::CREDENTIAL_INVALID => 'Store a new one on HQ > Credentials; HQ will not use the rejected one.',
        ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT => 'The account behind the key has no credit: add credit with the provider. The key itself is not judged. See docs/runbooks/provider-blocked.md.',
        ReadinessRow::PROVIDER_UNAUTHORIZED => 'The key lacks a permission: check what it is allowed to do in the provider console.',
    ];

    public function __construct(private readonly CapabilityReadiness $readiness) {}

    public function key(): string
    {
        return 'readiness.incident';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $conditions = [];

        foreach ($this->readiness->incidents() as $row) {
            $conditions[] = new AlertCondition("{$row->targetKind}:{$row->target}:{$row->provider}", 'warning',
                "{$row->targetName} cannot be used: {$row->state}",
                trim($row->reason.' '.(self::WHAT_TO_DO[$row->state] ?? 'See docs/runbooks/readiness.md.')),
                ['target_kind' => $row->targetKind, 'target' => $row->target, 'provider' => $row->provider, 'state' => $row->state]);
        }

        return $conditions;
    }
}
