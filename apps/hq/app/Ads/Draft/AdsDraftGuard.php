<?php

namespace App\Ads\Draft;

use App\Ads\Dto\AdMoney;

/**
 * The three checks every ad draft passes BEFORE the platform is called (docs/144-meta-ads-drafts), in code and not in
 * a prompt:
 *
 *  1. **Budget**: the daily budget of one campaign is at most the per-campaign cap (`hq.ads.draft.max_daily_usd`,
 *     USD 5 a day by Joy's standing rule), compared in whole minor units; a currency other than USD or BDT, or a
 *     BDT conversion rate that is not configured, fails closed.
 *  2. **Bangla only**: every copy field contains Bangla script and no Latin letters, except the brand names the
 *     configuration allows and web addresses. Digits are English digits (Joy's rule).
 *  3. **Health and quality claims (CRPA 2009 section 44, misleading advertising)**: copy that cures or treats, or
 *     makes an absolute, organic, chemical-free, freshness, certification or lab claim, is refused unless a person
 *     recorded the evidence for THAT claim on the spec. Curing or treating a disease has no override at all.
 *
 * It judges words, not meaning: a pass is "nothing a rule recognises", never "the copy is lawful". A person still
 * reads the draft in Ads Manager before switching it on; this is the floor under that, not a substitute.
 */
final class AdsDraftGuard
{
    /** claim key => [terms (lower-case), reason, overridable by recorded evidence] */
    public const CLAIMS = [
        'cure' => [['cure', 'treat', 'heal', 'remedy', 'cancer', 'diabetes', 'রোগ সার', 'নিরাময়', 'ক্যান্সার', 'ডায়াবেটিস', 'চিকিৎসা'], 'Claims to cure or treat a disease.', false],
        'organic' => [['organic', 'অর্গানিক', 'জৈব'], 'Calls the product organic.', true],
        'absolute' => [['100%', '১০০%', '১০০ %', 'hundred percent', 'শতভাগ', 'শতভাগ'], 'An absolute claim (100 percent).', true],
        'chemical_free' => [['chemical free', 'chemical-free', 'কেমিক্যাল মুক্ত', 'কেমিক্যালমুক্ত', 'রাসায়নিক মুক্ত', 'প্রিজারভেটিভ মুক্ত', 'preservative free', 'preservative-free'], 'Claims the product is free of chemicals or preservatives.', true],
        'fresh' => [['fresh', 'তাজা', 'সতেজ', 'ভরা মৌসুম'], 'A freshness claim.', true],
        'healthy' => [['healthy', 'স্বাস্থ্যকর', 'নিরাপদ', 'immunity', 'রোগ প্রতিরোধ'], 'A health or safety claim.', true],
        'certified' => [['bsti', 'বিএসটিআই', 'certified', 'সার্টিফাইড', 'lab tested', 'ল্যাব টেস্ট', 'ল্যাব-টেস্ট', 'ল্যাব পরীক্ষিত'], 'A certification or lab-test claim.', true],
        'boneless' => [['boneless', 'কাঁটাবিহীন', 'কাঁটা ছাড়া', 'কাটাবিহীন'], 'A boneless claim.', true],
    ];

    /**
     * @return array{ok: bool, budget: array<string, mixed>, language: array<string, mixed>, claims: array<string, mixed>}
     */
    public function check(AdsDraftSpec $spec): array
    {
        $budget = $this->budget($spec);
        $language = $this->language($spec);
        $claims = $this->claims($spec);

        return ['ok' => $budget['ok'] && $language['ok'] && $claims['ok'], 'budget' => $budget, 'language' => $language, 'claims' => $claims];
    }

    /** @return array<string, mixed> */
    public function budget(AdsDraftSpec $spec): array
    {
        $capUsd = (string) config('hq.ads.draft.max_daily_usd', '5.00');
        $currency = $spec->currency;
        try {
            $requested = AdMoney::toMinor($spec->dailyBudget, $currency);
            $capMinor = match ($currency) {
                'USD' => AdMoney::toMinor($capUsd, 'USD'),
                'BDT' => $this->bdtCap($capUsd),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'currency' => $currency, 'reason' => $e->getMessage()];
        }
        if ($capMinor === null) {
            return ['ok' => false, 'currency' => $currency, 'reason' => "A budget in {$currency} cannot be checked against the USD cap, so it is refused."];
        }

        return [
            'ok' => $requested > 0 && $requested <= $capMinor, 'currency' => $currency, 'requested_minor' => $requested, 'cap_minor' => $capMinor,
            'reason' => $requested <= 0 ? 'The budget must be above zero.' : ($requested > $capMinor ? 'The daily budget is over the per-campaign cap of USD '.$capUsd.'.' : null),
        ];
    }

    private function bdtCap(string $capUsd): ?int
    {
        $rate = (string) config('hq.ads.draft.usd_bdt_rate', '');
        if (preg_match('/^\d+(\.\d{1,2})?$/', $rate) !== 1 || (float) $rate <= 0) {
            return null; // no rate configured: refuse rather than guess one
        }
        // cap (USD minor) * rate (x100) / 100 => BDT minor, in whole integers
        $rateMinor = AdMoney::toMinor($rate, 'BDT');

        return intdiv(AdMoney::toMinor($capUsd, 'USD') * $rateMinor, 100);
    }

    /** @return array<string, mixed> */
    public function language(AdsDraftSpec $spec): array
    {
        $allowed = (array) config('hq.ads.draft.allowed_latin', ['LaLaDia', 'Bionic', 'Bionic Garden', 'Bor de Guna']);
        $failures = [];
        foreach ($spec->copy() as $text) {
            $scrub = preg_replace('#https?://\S+#u', ' ', $text) ?? $text;
            foreach ($allowed as $token) {
                $scrub = str_ireplace((string) $token, ' ', $scrub);
            }
            if (preg_match('/\p{Bengali}/u', $text) !== 1) {
                $failures[] = ['text' => mb_substr($text, 0, 60), 'why' => 'No Bangla in this text.'];
            } elseif (preg_match('/[A-Za-z]/', $scrub) === 1) {
                $failures[] = ['text' => mb_substr($text, 0, 60), 'why' => 'English letters in a Bangla-only ad.'];
            } elseif (preg_match('/[০-৯]/u', $text) === 1) {
                $failures[] = ['text' => mb_substr($text, 0, 60), 'why' => 'Use English digits.'];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    /** @return array<string, mixed> */
    public function claims(AdsDraftSpec $spec): array
    {
        $haystack = mb_strtolower(implode("\n", $spec->copy()));
        $hits = [];
        foreach (self::CLAIMS as $key => [$terms, $reason, $overridable]) {
            foreach ($terms as $term) {
                if (str_contains($haystack, mb_strtolower($term))) {
                    $proof = trim((string) ($spec->claimProofs[$key] ?? ''));
                    $hits[] = ['claim' => $key, 'term' => $term, 'reason' => $reason, 'overridable' => $overridable, 'proof' => $overridable && $proof !== '' ? $proof : null];
                    break;
                }
            }
        }
        $blocking = array_filter($hits, static fn (array $h) => $h['proof'] === null);

        return ['ok' => $blocking === [], 'hits' => $hits];
    }
}
