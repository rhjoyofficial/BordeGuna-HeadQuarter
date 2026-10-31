<?php

namespace App\Content;

use App\Models\ContentBrief;
use App\Models\ContentClaimsRule;

/**
 * The claims policy a draft is checked against (docs/145-a): the brief's own policy, plus the owner's
 * active rules for the business from `content_claims_rules`, plus the `ClaimsFloor`.
 *
 * Every source can only ADD a forbidden phrase; none can remove one. A product line counts as covered
 * when the brief covers it or the business has at least one active rule written for that exact line,
 * so a line nobody wrote anything about still fails closed (HQ never infers what may be claimed).
 *
 * The result records the rules it was built from (ids and a digest), so a check made today can be
 * explained after a rule is retired.
 */
final class ClaimsRulebook
{
    public function policyFor(ContentBrief $brief, int $businessId): ClaimsPolicy
    {
        $base = $brief->policy();
        $lines = $base->lines;
        $rules = $this->activeRules($businessId);

        foreach ($rules as $rule) {
            $line = (string) $rule->product_line;
            $lines[$line] ??= ['label' => $line, 'forbidden' => [], 'allowed' => []];
            if ($rule->kind === 'forbidden') {
                $lines[$line]['forbidden'][] = [
                    'id' => 'rule.'.$rule->uuid,
                    'phrases' => array_values(array_map('strval', (array) $rule->phrases)),
                    'reason' => (string) $rule->reason.($rule->legal_ref ? " ({$rule->legal_ref})" : ''),
                ];
            } else {
                $lines[$line]['allowed'] = [...($lines[$line]['allowed'] ?? []), ...array_map('strval', (array) $rule->phrases)];
            }
        }

        $lines[ClaimsPolicy::FLOOR_LINE] = ['label' => 'floor', 'forbidden' => ClaimsFloor::rules()];

        $ids = $rules->pluck('uuid')->map(fn ($u) => (string) $u)->all();
        sort($ids);
        $version = $base->version.'+rules:'.substr(hash('sha256', implode(',', $ids)), 0, 12).'+'.ClaimsFloor::VERSION;

        return new ClaimsPolicy($version, $lines, $base->bannedWords);
    }

    /** @return \Illuminate\Support\Collection<int, ContentClaimsRule> */
    public function activeRules(int $businessId)
    {
        return ContentClaimsRule::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('id')->get();
    }
}
