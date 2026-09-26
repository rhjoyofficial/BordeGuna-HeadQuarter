<?php

namespace App\Ads;

use App\Ads\Dto\AdInsightRow;
use App\Models\FactsAdAccount;
use App\Models\FactsAdSpend;
use App\Models\PlatformAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copies one ad account's delivery into HQ's read models (Phase 5 read side, `docs/84` C.14, E P5-G1).
 *
 * ⛔ **DEFINED, NOT SCHEDULED.** Nothing runs this on a clock: there is no `scheduled_jobs` row and no
 * `Schedule::` entry for it (a test holds both), its tool `ads.sync_ad_spend` is not registered, and
 * it cannot authenticate because no ads credential exists. Scheduling it hourly is gate P5-G1.
 *
 * What it writes is HQ's own state only (the two `facts_ad_*` tables); it changes nothing at the
 * platform, which it reaches through an `AdsReader` and that has no write method.
 *
 * Idempotent: the same account and days read twice leave the same rows (one per object per day per
 * level, replaced with the newer reading). Days the platform does not report (Meta omits a day with
 * no delivery) are left as they were, never zeroed by inference.
 */
final class AdSpendSync
{
    /**
     * @param  list<string>  $levels
     * @return array{account: array, rows: int, levels: array<string, int>, currencies: list<string>, days: array{since: string, until: string}}
     */
    public function sync(PlatformAccount $account, AdsReader $reader, CarbonImmutable $since, CarbonImmutable $until, ?string $syncRef = null, array $levels = AdsReader::LEVELS): array
    {
        if ($until->lt($since)) {
            throw new InvalidArgumentException('The sync range ends before it starts.');
        }
        if ($since->diffInDays($until) > 90) {
            // Meta's own window for daily increments is 1 to 90 days; a longer backfill is several calls.
            throw new InvalidArgumentException('Sync at most 90 days in one call.');
        }
        $brand = $account->brand()->first();
        if ($brand === null) {
            throw new InvalidArgumentException("Platform account [{$account->account_ref}] has no brand; spend without a brand has no business.");
        }

        $fetchedAt = CarbonImmutable::now();
        $read = $reader->account();
        $counts = [];
        $currencies = [$read->currency];

        // Read everything first, write after: a failure half way through a read leaves the tables as
        // they were rather than half replaced.
        $rows = [];
        foreach ($levels as $level) {
            $rows[$level] = $reader->insights($level, $since, $until);
        }

        DB::transaction(function () use ($account, $brand, $read, $reader, $rows, $fetchedAt, $syncRef, &$counts) {
            FactsAdAccount::updateOrCreate(['platform_account_id' => $account->id], [
                'business_id' => $brand->business_id,
                'brand_id' => $brand->id,
                'platform' => $account->platform,
                'account_ref' => $read->accountRef,
                'name' => $read->name,
                'currency' => $read->currency,
                'timezone' => $read->timezone,
                'status' => $read->status,
                'spend_cap_minor' => $read->spendCapMinor === null ? null : (int) $read->spendCapMinor,
                'amount_spent_minor' => $read->amountSpentMinor === null ? null : (int) $read->amountSpentMinor,
                'spend_cap' => $read->spendCap,
                'amount_spent' => $read->amountSpent,
                'provenance' => 'third_party',
                'source' => 'meta_ads',
                'api_version' => $reader->apiVersion(),
                'fetched_at' => $fetchedAt,
                'sync_ref' => $syncRef,
            ]);

            foreach ($rows as $level => $insights) {
                $counts[$level] = 0;
                /** @var AdInsightRow $row */
                foreach ($insights as $row) {
                    if ($row->currency !== $read->currency) {
                        // An account reports in one currency; a row in another is a platform answer HQ
                        // does not understand, refused rather than stored beside the others.
                        throw new InvalidArgumentException("Insights row in [{$row->currency}] for an account in [{$read->currency}].");
                    }
                    FactsAdSpend::updateOrCreate([
                        'platform_account_id' => $account->id,
                        'level' => $level,
                        'object_ref' => $row->objectRef,
                        'spend_date' => $row->date,
                    ], [
                        'business_id' => $brand->business_id,
                        'brand_id' => $brand->id,
                        'platform' => $account->platform,
                        'account_ref' => $read->accountRef,
                        'object_name' => $row->objectName,
                        'campaign_ref' => $row->campaignRef,
                        'adset_ref' => $row->adsetRef,
                        'ad_ref' => $row->adRef,
                        'currency' => $row->currency,
                        'spend' => $row->spend,
                        'impressions' => $row->impressions,
                        'clicks' => $row->clicks,
                        'reach' => $row->reach,
                        'purchases' => $row->purchases,
                        'purchase_value' => $row->purchaseValue,
                        'provenance' => 'third_party',
                        'source' => 'meta_ads',
                        'api_version' => $reader->apiVersion(),
                        'fetched_at' => $fetchedAt,
                        'sync_ref' => $syncRef,
                    ]);
                    $counts[$level]++;
                }
            }
        });

        return [
            'account' => $read->toArray(),
            'rows' => array_sum($counts),
            'levels' => $counts,
            'currencies' => array_values(array_unique($currencies)),
            'days' => ['since' => $since->toDateString(), 'until' => $until->toDateString()],
        ];
    }
}
