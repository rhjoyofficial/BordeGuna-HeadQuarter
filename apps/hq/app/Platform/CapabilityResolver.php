<?php

namespace App\Platform;

use App\Models\PlatformAccount;

/**
 * What a CONNECTED ACCOUNT can do right now (ADR-022).
 *
 * The rule, in one line: **the provider's ceiling narrowed by the account's actual state**, never
 * the ceiling on its own. Platform access is not a property of a platform. Two Facebook Pages in
 * one group hold different scopes, sit at different stages of App Review and carry different
 * quotas, and for months at a time some of their capabilities are simply pending.
 *
 * ⛔ **Absent, not annotated.** A capability that is not usable right now is missing from the
 * effective set, so an agent cannot offer it. The reason it is missing is kept separately, so the
 * agent can explain rather than merely fail. `platform_accounts` has carried `capabilities`,
 * `scopes`, `approval_status`, `quotas` and `restrictions` since Phase 0 with nothing reading them;
 * this is what they were for.
 *
 * Fail-closed at every step: an inactive account, an unknown platform, or an account whose grants
 * have never been recorded all resolve to NOTHING.
 */
final readonly class CapabilityResolver
{
    public function __construct(private PlatformRegistry $registry) {}

    public function resolve(PlatformAccount $account): CapabilityResolution
    {
        if (! $account->is_active) {
            return CapabilityResolution::none("platform account [{$account->platform}/{$account->account_ref}] is not active");
        }

        $provider = $this->registry->get($account->platform);
        if ($provider === null) {
            // Not "assume it works". HQ has no code for this platform, so it can do nothing here.
            return CapabilityResolution::none("no provider is registered for platform [{$account->platform}]");
        }

        $ceiling = $provider->ceiling();
        $accountGrants = $this->stringList($account->capabilities);
        // Null restrictions genuinely means "none", unlike null capabilities, which means "nobody
        // has recorded what this account holds". The two nulls answer different questions and only
        // one of them is safe to read as an empty list.
        $restrictions = $this->stringList($account->restrictions) ?? [];
        $approved = $account->approval_status === 'approved';

        $effective = [];
        $withheld = [];

        foreach ($ceiling->granted() as $operation) {
            if ($accountGrants === null) {
                // The grants for this account have never been recorded. That is not the same as
                // "none were granted", but it must resolve the same way, because the alternative is
                // an agent acting on an assumption nobody made.
                $withheld[$operation] = 'this account has no recorded capability grants; record them before use';

                continue;
            }

            if (! in_array($operation, $accountGrants, true)) {
                $withheld[$operation] = 'not granted to this connected account';

                continue;
            }

            if (in_array($operation, $restrictions, true)) {
                $withheld[$operation] = 'restricted on this account';

                continue;
            }

            if (! $approved && in_array($operation, $provider->requiresApproval(), true)) {
                $withheld[$operation] = "platform approval is [{$account->approval_status}]; this operation needs an approved account";

                continue;
            }

            $effective[$operation] = true;
        }

        return new CapabilityResolution(new Capabilities($effective), $withheld, $this->array($account->quotas));
    }

    /** @return list<string>|null null means the column was never populated, which is not [] */
    private function stringList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn ($v) => is_string($v) && $v !== '',
        ));
    }

    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
