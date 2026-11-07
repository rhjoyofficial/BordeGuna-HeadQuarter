<?php

namespace App\Content;

use App\Briefing\EvidenceBundle;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\Freshness;
use App\Briefing\NarrativeNumberCheck;
use App\Models\Brand;
use App\Models\ContentBrief;
use App\Models\ContentTestimonial;
use Carbon\CarbonImmutable;

/**
 * The checks every draft passes before a person sees it (docs/85 C.8), and again at publish time.
 *
 * 1. **`content.numbers.v1`**: `briefing.numbers.v1` pointed outwards. Every figure in the copy (a
 *    price, a weight, a percentage, a date, a count, in Latin or Bangla digits) must equal a product
 *    fact read from a system of record or a value a cited research fact carries. Exact, strict, no
 *    rounding, nothing computed: the same class that checks the Executive's briefing checks the post.
 * 2. **The claims policy** of the brief, for the product lines the draft is about, plus the lines'
 *    shared rules. A product line the policy does not cover fails closed.
 * 3. **Banned words** of the brief.
 * 4. (docs/145-a) the owner's active `content_claims_rules` for the business and the `ClaimsFloor`
 *    (no unproven health claim, no testimonial), merged into the policy by `ClaimsRulebook`; each only adds.
 * 5. (ChatGPT ruling 13, 31 Oct 2026) a draft that names a recorded testimonial is judged by
 *    `ContentTestimonials::judge()`. Only when every condition holds is the floor's `floor.testimonial`
 *    phrase rule waived, for that draft only. Every other rule (the health floor, the owner's rules,
 *    banned words, numbers) still runs on the whole text, quote included. A named testimonial that does
 *    not qualify fails the draft.
 *
 * ⛔ A draft that fails goes to a person marked failed. Nothing here, or anywhere, corrects the text.
 */
final class ContentChecks
{
    public const RULE = 'content.numbers.v1';

    public function __construct(
        private readonly NarrativeNumberCheck $numbers,
        private readonly ClaimsRulebook $rulebook,
        private readonly ContentTestimonials $testimonials,
    ) {}

    /**
     * @param  list<string>  $productLines
     * @param  list<ProductFact>  $facts
     * @param  list<array<string, mixed>>  $citedClaims  research claims the draft relies on
     * @param  array{testimonial: ContentTestimonial, text_provenance: string, platform: string, assets: list<array{media_asset_id: int, role: string}>, edited: bool}|null  $quote
     */
    public function run(string $text, ContentBrief $brief, array $productLines, array $facts, array $citedClaims, ?string $business = null, ?string $brand = null, ?array $quote = null): array
    {
        // docs/145-a: the brief's policy, plus the owner's active rules for the business, plus the floor.
        $policy = $this->rulebook->policyFor($brief, (int) Brand::query()->whereKey($brief->brand_id)->value('business_id'));

        $uncovered = array_values(array_filter($productLines, static fn (string $l): bool => ! $policy->covers($l)));
        $violations = $policy->violations($text, $productLines);
        $banned = $policy->bannedWordsIn($text);

        // Ruling 13: the narrow testimonial exception. It can only remove the floor's testimonial phrase rule;
        // a testimonial that does not qualify keeps every violation and fails the draft by itself.
        $testimonial = null;
        if ($quote !== null) {
            $testimonial = $this->testimonials->judge($quote['testimonial'], $text, (string) $quote['text_provenance'],
                (int) Brand::query()->whereKey($brief->brand_id)->value('business_id'), (int) $brief->brand_id,
                (string) $quote['platform'], (array) ($quote['assets'] ?? []), (bool) ($quote['edited'] ?? false));
            if ($testimonial['applies']) {
                $violations = array_values(array_filter($violations, static fn (array $v): bool => $v['rule'] !== ContentTestimonials::WAIVABLE_RULE));
            }
        }

        $numbers = $this->numbers->check($text, self::bundle($facts, $citedClaims, $business, $brand));

        $claimsPassed = $uncovered === [] && $productLines !== [] && $violations === [] && ($testimonial === null || $testimonial['applies']);

        return [
            'passed' => $numbers->passed() && $claimsPassed && $banned === [],
            'numbers' => [
                'rule' => self::RULE,
                'built_on' => NarrativeNumberCheck::RULE,
                'passed' => $numbers->passed(),
                'unsourced' => array_values(array_map(static fn (array $f): array => [
                    'figure' => $f['figure'], 'type' => $f['type'], 'why' => $f['why'],
                ], $numbers->unsourced())),
            ],
            'claims_policy' => [
                'passed' => $claimsPassed,
                'policy_version' => $policy->version,
                'product_lines' => $productLines,
                'uncovered_product_lines' => $uncovered,
                'violations' => $violations,
                'testimonial' => $testimonial,
                'why' => match (true) {
                    $testimonial !== null && ! $testimonial['applies'] => 'The quoted testimonial does not qualify: '.implode(' ', $testimonial['reasons']),
                    $productLines === [] => 'The draft names no product line, so no claims policy can be applied to it.',
                    $uncovered !== [] => 'No claims policy covers '.implode(', ', $uncovered).'. HQ never infers what may be claimed about a product.',
                    $violations !== [] => 'The copy uses a phrase the owner\'s claims policy forbids.',
                    default => null,
                },
            ],
            'banned_words' => ['passed' => $banned === [], 'found' => $banned],
            'brief' => ['id' => $brief->getKey(), 'version' => (int) $brief->version],
        ];
    }

    /**
     * The evidence a draft may speak from, in the briefing's own shape so the same number check reads it.
     *
     * @param  list<ProductFact>  $facts
     * @param  list<array<string, mixed>>  $citedClaims
     */
    public static function bundle(array $facts, array $citedClaims, ?string $business = null, ?string $brand = null): EvidenceBundle
    {
        $tz = (string) config('hq.timezone', 'Asia/Dhaka');
        $now = CarbonImmutable::now($tz);
        $scope = new EvidenceScope($business, $brand);

        $items = [];
        foreach ($facts as $fact) {
            $items[] = new EvidenceItem(
                id: 'product:'.$fact->key,
                kind: EvidenceKind::Fact,
                section: 'product',
                label: $fact->label,
                value: $fact->value,
                unit: self::unit($fact->unit, $fact->value),
                scope: $scope,
                source: ['system' => $fact->system, 'read_model' => 'live_read', 'ref' => $fact->ref],
                asOf: $fact->asOf,
                freshness: Freshness::live(),
                subject: $fact->sku,
            );
        }

        foreach ($citedClaims as $claim) {
            if (($claim['value'] ?? null) === null) {
                continue;
            }
            $items[] = new EvidenceItem(
                id: 'research:'.$claim['id'],
                kind: ($claim['kind'] ?? '') === 'metric' ? EvidenceKind::Metric : EvidenceKind::Fact,
                section: 'research',
                label: mb_substr((string) $claim['statement'], 0, 120),
                value: (string) $claim['value'],
                unit: self::unit((string) ($claim['unit'] ?? ''), (string) $claim['value']),
                scope: $scope,
                source: ['system' => (string) ($claim['system'] ?? 'research'), 'read_model' => 'research_artifacts', 'ref' => (string) ($claim['url'] ?? $claim['ref'] ?? $claim['id'])],
                asOf: null,
                freshness: Freshness::live(),
            );
        }

        return new EvidenceBundle(
            capability: 'content.draft',
            briefingDate: $now->toDateString(),
            timezone: $tz,
            generatedAt: $now,
            yesterday: ['date' => $now->subDay()->toDateString(), 'from' => '', 'to' => ''],
            today: ['date' => $now->toDateString(), 'from' => '', 'to' => ''],
            scope: ['business' => $business, 'permitted_business_ids' => null],
            sources: [],
            sections: [],
            items: $items,
        );
    }

    private static function unit(string $unit, int|string $value): string
    {
        $u = mb_strtolower(trim($unit));

        return match (true) {
            in_array($u, ['bdt', 'taka', 'tk', '৳', 'টাকা'], true) => 'BDT',
            in_array($u, ['percent', '%', 'শতাংশ'], true) => 'percent',
            ! is_int($value) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) !== 1 => 'text',
            default => 'units',
        };
    }
}
