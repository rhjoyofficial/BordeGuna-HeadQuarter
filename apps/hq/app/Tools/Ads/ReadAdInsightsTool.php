<?php

namespace App\Tools\Ads;

use App\Ads\AdsReader;
use App\Ads\Dto\AdInsightRow;
use App\Ads\Dto\AdMoney;
use App\Models\PlatformAccount;
use App\Platform\Contracts\AdsPlatform;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Daily delivery of one ad account (spend, impressions, clicks, reach, the platform's attributed
 * purchases), read live for a closed range of at most 90 days (R2).
 *
 * ⚠ The purchases are Meta's count, never delivered orders (`docs/84` C.17): the contribution report
 * reads deliveries from the business's systems, never from here.
 */
final class ReadAdInsightsTool extends AdsTool
{
    public function key(): string
    {
        return 'ads.read_insights';
    }

    protected function title(): string
    {
        return 'Read ad insights';
    }

    protected function purpose(): string
    {
        return 'Reads daily delivery of one connected ad account of a business for a date range of at most 90 days, at campaign, ad set '
            .'or ad level: spend in the account\'s own currency, impressions, clicks, reach and the platform\'s own attributed purchases. '
            .'Read-only; the platform\'s count, never delivered orders.';
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
            'level' => ['type' => 'string', 'enum' => AdsReader::LEVELS],
        ];
    }

    protected function outputProperties(): array
    {
        return [
            'level' => ['type' => 'string'],
            'since' => ['type' => 'string'],
            'until' => ['type' => 'string'],
            'currency' => ['type' => 'string'],
            'rows' => ['type' => 'array'],
            'totals' => ['type' => 'object'],
        ];
    }

    protected function read(AdsReader $reader, PlatformAccount $account, array $payload, ToolContext $context): array|ToolResult
    {
        $today = CarbonImmutable::now((string) config('hq.timezone', 'Asia/Dhaka'));
        $until = self::day($payload['until'] ?? null, $today);
        $since = self::day($payload['since'] ?? null, $until->subDays(6));
        if ($until->lt($since)) {
            return ToolResult::failed('The range ends before it starts.');
        }
        if ($since->diffInDays($until) > 89) {
            return ToolResult::failed('Ask for at most 90 days at a time.');
        }
        $level = (string) ($payload['level'] ?? 'campaign');
        $rows = $reader->insights($level, $since, $until);
        $currency = $reader->account()->currency;

        $impressions = $clicks = 0;
        foreach ($rows as $row) {
            $impressions += $row->impressions;
            $clicks += $row->clicks;
        }

        return [
            'level' => $level,
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
            'currency' => $currency,
            'rows' => array_map(static fn (AdInsightRow $r) => $r->toArray(), $rows),
            'totals' => [
                'currency' => $currency,
                'spend' => AdMoney::sum(array_map(static fn (AdInsightRow $r) => $r->spend, $rows), $currency),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'rows' => count($rows),
            ],
        ];
    }
}
