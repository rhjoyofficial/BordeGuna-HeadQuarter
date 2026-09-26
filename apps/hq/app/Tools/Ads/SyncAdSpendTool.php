<?php

namespace App\Tools\Ads;

use App\Ads\AdSpendSync;
use App\Ads\AdsReader;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Models\PlatformAccount;
use App\Platform\CapabilityRouter;
use App\Platform\Contracts\AdsPlatform;
use App\Platform\PlatformRegistry;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Copies one ad account's daily delivery into `facts_ad_accounts` and `facts_ad_spend` (P5-G1's
 * hourly sync, `docs/84` E).
 *
 * ⛔ **DEFINED, NOT SCHEDULED, NOT REGISTERED.** No `scheduled_jobs` row names it and no `Schedule::`
 * entry runs it (a test holds both); it is not in `config('hq.tools')`, so neither a person nor a
 * job can reach it. Scheduling it hourly is gate P5-G1.
 *
 * R3, like the ERP polls: it reads the platform (R2) and writes HQ's own read models (R3, HQ state
 * only). Not agent-executable: a sync is infrastructure, not a question.
 */
final class SyncAdSpendTool extends AdsTool
{
    public function __construct(
        CapabilityRouter $router,
        PlatformRegistry $platforms,
        private readonly AdSpendSync $sync,
    ) {
        parent::__construct($router, $platforms);
    }

    public function key(): string
    {
        return 'ads.sync_ad_spend';
    }

    protected function title(): string
    {
        return 'Sync ad spend';
    }

    protected function purpose(): string
    {
        return 'Copies one connected ad account\'s daily delivery for a range of at most 90 days (default the last three days) into HQ\'s '
            .'ad spend read model, with provenance. Reads the platform; writes only HQ\'s own facts.';
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R3;
    }

    protected function readWrite(): ReadWrite
    {
        return ReadWrite::Write;
    }

    protected function agentExecutable(): bool
    {
        return false;
    }

    protected function capability(): string
    {
        return AdsPlatform::READ_INSIGHTS;
    }

    protected function arguments(): array
    {
        return [
            'since' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 10],
            'until' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 10],
        ];
    }

    protected function outputProperties(): array
    {
        return ['rows' => ['type' => 'integer'], 'levels' => ['type' => 'object'], 'days' => ['type' => 'object']];
    }

    protected function read(AdsReader $reader, PlatformAccount $account, array $payload, ToolContext $context): array|ToolResult
    {
        $today = CarbonImmutable::now((string) config('hq.timezone', 'Asia/Dhaka'));
        $until = self::day($payload['until'] ?? null, $today);
        $since = self::day($payload['since'] ?? null, $until->subDays(2));
        if ($until->lt($since) || $since->diffInDays($until) > 89) {
            return ToolResult::failed('Sync a range of one to 90 days that ends after it starts.');
        }

        return $this->sync->sync($account, $reader, $since, $until, $context->correlationId);
    }
}
