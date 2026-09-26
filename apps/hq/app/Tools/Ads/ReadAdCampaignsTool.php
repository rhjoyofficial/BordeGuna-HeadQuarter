<?php

namespace App\Tools\Ads;

use App\Ads\AdsReader;
use App\Ads\Dto\AdObjectRead;
use App\Models\PlatformAccount;
use App\Platform\Contracts\AdsPlatform;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * The campaigns, ad sets or ads of one ad account, with status and budgets, read live (R2).
 */
final class ReadAdCampaignsTool extends AdsTool
{
    public function key(): string
    {
        return 'ads.read_campaigns';
    }

    protected function title(): string
    {
        return 'Read ad campaigns';
    }

    protected function purpose(): string
    {
        return 'Reads the campaigns, ad sets or ads of one connected ad account of a business, with their status, objective and '
            .'budgets in the account\'s own currency, as the platform states them. Read-only.';
    }

    protected function capability(): string
    {
        return AdsPlatform::READ_CAMPAIGNS;
    }

    protected function arguments(): array
    {
        return ['level' => ['type' => 'string', 'enum' => AdsReader::LEVELS]];
    }

    protected function outputProperties(): array
    {
        return [
            'level' => ['type' => 'string'],
            'currency' => ['type' => 'string'],
            'objects' => ['type' => 'array'],
            'count' => ['type' => 'integer'],
        ];
    }

    protected function read(AdsReader $reader, PlatformAccount $account, array $payload, ToolContext $context): array|ToolResult
    {
        $level = (string) ($payload['level'] ?? 'campaign');
        $objects = match ($level) {
            'adset' => $reader->adSets(),
            'ad' => $reader->ads(),
            default => $reader->campaigns(),
        };

        return [
            'level' => $level,
            'currency' => $reader->account()->currency,
            'objects' => array_map(static fn (AdObjectRead $o) => $o->toArray(), $objects),
            'count' => count($objects),
        ];
    }
}
