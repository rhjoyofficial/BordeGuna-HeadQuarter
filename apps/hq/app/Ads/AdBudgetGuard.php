<?php

namespace App\Ads;

use App\Ads\Dto\AdMoney;
use App\Models\Business;
use App\Models\FactsAdAccount;
use App\Models\FactsAdSpend;
use App\Models\Policy;
use Carbon\CarbonImmutable;

/**
 * Where a business stands against the ceilings on advertising money (`docs/84` C.11). READS ONLY.
 *
 * ⛔ **It never writes, anywhere.** Not at the platform (it has no reader and no connector), and not
 * in HQ (no row, no audit, no cache): `AdBudgetGuardTest` records every query it runs and fails on any
 * insert, update or delete. It answers two questions and changes nothing:
 *
 *  1. `status()`: per ad account, the OWNER's spending limit as last read and what remains under it;
 *     and HQ's own monthly budget for the business (a policy row the owner sets) against the
 *     month-to-date spend HQ has read.
 *  2. `fits()`: could a proposed amount fit under BOTH ceilings? It answers NO whenever anything is
 *     unknown: no limit set, no HQ budget set, stale or missing account facts, or a currency that
 *     differs. Room under a ceiling is never permission to spend (ADR-050 clarification 9); `fits()`
 *     is a precondition a future approval-gated tool will check, never an approval.
 *
 * Money is compared only within one currency; a BDT budget and a USD account are "not comparable",
 * never converted (`docs/84` C.21).
 */
final class AdBudgetGuard
{
    /** The policy key for HQ's per-business monthly advertising budget: rules {amount, currency}. */
    public const POLICY_KEY = 'ads.monthly_budget';

    /** A spend at or above this share of a ceiling is "near". */
    public const NEAR_SHARE = 0.8;

    /** Account facts older than this are stale, and a stale ceiling is an unknown one. */
    public const STALE_AFTER_HOURS = 6;

    /** @return array<string, mixed> */
    public function status(Business $business, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $accounts = FactsAdAccount::query()->where('business_id', $business->id)->orderBy('account_ref')->get();

        $owner = $accounts->map(function (FactsAdAccount $a) use ($now) {
            $stale = $a->fetched_at === null || CarbonImmutable::parse($a->fetched_at)->lt($now->subHours(self::STALE_AFTER_HOURS));
            $set = $a->spend_cap_minor !== null;
            $remaining = $set && $a->amount_spent_minor !== null
                ? AdMoney::fromMinor((string) ($a->spend_cap_minor - $a->amount_spent_minor), $a->currency)
                : null;

            return [
                'account_ref' => $a->account_ref,
                'currency' => $a->currency,
                'status' => $a->status,
                'spend_cap' => $set ? AdMoney::fromMinor((string) $a->spend_cap_minor, $a->currency) : null,
                'amount_spent' => $a->amount_spent_minor === null ? null : AdMoney::fromMinor((string) $a->amount_spent_minor, $a->currency),
                'remaining_under_cap' => $remaining,
                'state' => $stale ? 'stale' : ($set ? self::share($a->amount_spent_minor ?? 0, $a->spend_cap_minor) : 'no_limit_set'),
                'read_at' => $a->fetched_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'business' => $business->slug,
            'owner_limits' => $owner,
            'hq_budget' => $this->hqBudget($business, $now),
            'provenance' => [
                'owner_limits' => 'third_party: the platform\'s own statement of the owner\'s limit, as last read',
                'hq_budget' => 'system: the owner\'s budget policy on HQ',
                'month_to_date_spend' => 'third_party: facts_ad_spend, campaign level, as last read',
            ],
        ];
    }

    /**
     * Could $amount (major unit, in $currency) fit under the owner's limit on the account AND HQ's
     * monthly budget? `fits` is true only when every ceiling is known, fresh, in the same currency
     * and has room. Otherwise false, with every reason.
     *
     * @return array{fits: bool, reasons: list<string>}
     */
    public function fits(Business $business, string $accountRef, string $amount, string $currency, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $currency = strtoupper($currency);
        $reasons = [];
        $want = AdMoney::toMinor($amount, $currency);

        $account = FactsAdAccount::query()->where('business_id', $business->id)
            ->whereIn('account_ref', [$accountRef, 'act_'.preg_replace('/^act_/', '', $accountRef)])->first();
        if ($account === null) {
            $reasons[] = "no facts for ad account [{$accountRef}] in {$business->slug}: read it before asking";
        } else {
            if ($account->fetched_at === null || CarbonImmutable::parse($account->fetched_at)->lt($now->subHours(self::STALE_AFTER_HOURS))) {
                $reasons[] = 'the account facts are stale; a stale ceiling is an unknown one';
            }
            if ($account->currency !== $currency) {
                $reasons[] = "the account is in {$account->currency}, the amount in {$currency}: not comparable, never converted";
            } elseif ($account->spend_cap_minor === null) {
                $reasons[] = 'the owner has set no spending limit on this account; without the outer ceiling nothing fits';
            } elseif ($account->amount_spent_minor === null) {
                $reasons[] = 'the amount spent against the limit is unknown';
            } elseif ($account->spend_cap_minor - $account->amount_spent_minor < $want) {
                $reasons[] = 'the amount does not fit under what remains of the owner\'s spending limit';
            }
        }

        $hq = $this->hqBudget($business, $now);
        if ($hq['state'] !== 'set') {
            $reasons[] = 'HQ has no monthly advertising budget set for this business';
        } elseif ($hq['currency'] !== $currency) {
            $reasons[] = "HQ's budget is in {$hq['currency']}, the amount in {$currency}: not comparable, never converted";
        } elseif ($hq['remaining'] === null || AdMoney::toMinor($hq['remaining'], $currency) < $want) {
            $reasons[] = 'the amount does not fit under what remains of HQ\'s monthly budget';
        }

        return ['fits' => $reasons === [], 'reasons' => $reasons];
    }

    /** @return array<string, mixed> */
    private function hqBudget(Business $business, CarbonImmutable $now): array
    {
        $policy = Policy::query()->where('key', self::POLICY_KEY)->where('business_id', $business->id)
            ->activeAt($now)->orderByDesc('active_from')->orderByDesc('version')->first();
        $rules = is_array($policy?->rules) ? $policy->rules : [];
        $amount = $rules['amount'] ?? null;
        $currency = isset($rules['currency']) ? strtoupper((string) $rules['currency']) : null;

        if ($policy === null || $amount === null || $currency === null) {
            return ['state' => 'not_set', 'amount' => null, 'currency' => null, 'month_to_date_spend' => null, 'remaining' => null, 'verdict' => 'unknown'];
        }

        $tz = (string) config('hq.timezone', 'Asia/Dhaka');
        $local = $now->setTimezone($tz);
        $spendByCurrency = FactsAdSpend::query()
            ->where('business_id', $business->id)
            ->where('level', 'campaign')
            ->whereBetween('spend_date', [$local->startOfMonth()->toDateString(), $local->toDateString()])
            ->get(['currency', 'spend'])
            ->groupBy('currency');

        $other = $spendByCurrency->keys()->reject(fn ($c) => $c === $currency)->values()->all();
        $spent = AdMoney::sum(($spendByCurrency->get($currency) ?? collect())->pluck('spend')->map(fn ($s) => (string) $s), $currency);
        $budgetMinor = AdMoney::toMinor((string) $amount, $currency);
        $spentMinor = AdMoney::toMinor($spent, $currency);

        return [
            'state' => 'set',
            'policy_version' => $policy->version,
            'amount' => AdMoney::major((string) $amount, $currency),
            'currency' => $currency,
            'month' => $local->format('Y-m'),
            'month_to_date_spend' => $spent,
            'remaining' => AdMoney::fromMinor((string) ($budgetMinor - $spentMinor), $currency),
            // Spend in another currency cannot be counted against this budget, and a verdict that
            // ignored it would read as more room than there is.
            'spend_in_other_currencies' => $other,
            'verdict' => $other !== [] ? 'not_comparable' : self::share($spentMinor, $budgetMinor),
        ];
    }

    private static function share(int $spent, int $ceiling): string
    {
        if ($ceiling <= 0) {
            return 'unknown';
        }
        if ($spent >= $ceiling) {
            return 'over';
        }

        return $spent >= (int) floor($ceiling * self::NEAR_SHARE) ? 'near' : 'within';
    }
}
