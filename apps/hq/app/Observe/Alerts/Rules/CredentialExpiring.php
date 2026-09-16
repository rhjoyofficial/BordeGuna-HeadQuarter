<?php

namespace App\Observe\Alerts\Rules;

use App\Models\IntegrationCredential;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * An active credential that expires soon, or has expired while still marked active (docs/48 N.5).
 * Names the provider and account only; a credential's value never leaves the vault.
 */
final class CredentialExpiring implements AlertRule
{
    public function key(): string
    {
        return 'credentials.expiring';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $days = max(1, (int) config('hq.alerts.thresholds.credential_expiry_days', 14));
        $timezone = (string) config('hq.timezone');
        $conditions = [];

        $expiring = IntegrationCredential::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now->addDays($days))
            ->orderBy('expires_at')
            ->get(['id', 'provider', 'account_ref', 'expires_at']);

        foreach ($expiring as $credential) {
            $expired = $credential->expires_at->lte($now);
            $label = $credential->provider.($credential->account_ref ? " ({$credential->account_ref})" : '');
            $when = $credential->expires_at->timezone($timezone)->format('D j M H:i T');

            $conditions[] = new AlertCondition("credential:{$credential->id}", $expired ? 'critical' : 'warning',
                $expired ? "Credential {$label} has expired" : "Credential {$label} expires {$when}",
                ($expired ? "It expired {$when} and HQ will not use it." : 'HQ stops using it at that moment.')
                .' Store a replacement on HQ > Credentials; storing replaces the old one.',
                ['credential_id' => $credential->id, 'provider' => $credential->provider, 'account_ref' => $credential->account_ref,
                    'expires_at' => $credential->expires_at->toIso8601String()]);
        }

        return $conditions;
    }
}
