<?php

namespace App\Content;

use App\Models\Business;
use App\Models\ContentClaimsRule;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;

/**
 * The owner's claims rules per product line (docs/89 §3.7 "the policy is Joy's decision", docs/145-a).
 *
 * A person with `hq.content.claims.manage` writes a rule; nothing else does, and no agent holds that
 * permission. A rule is never edited (a database trigger holds it): a change is a retirement and a new
 * rule, both audited. An ALLOWED claim must name the proof it rests on (a lab report, a certificate),
 * because the allowed list is what a writer reads as "safe to say".
 */
final class ClaimsRules
{
    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /** @param  array{product_line: string, kind: string, phrases: list<string>, reason: string, legal_ref?: ?string, proof_ref?: ?string}  $data */
    public function write(User $by, Business $business, array $data): ContentClaimsRule
    {
        if (! $this->scope->allows($by, (int) $business->getKey())) {
            throw new ResearchRefused('That business is outside what you may act on.');
        }

        $line = trim((string) ($data['product_line'] ?? ''));
        if ($line !== '*' && preg_match('/^[a-z0-9_]{1,64}$/', $line) !== 1) {
            throw new ResearchRefused('A product line is a key of lower-case letters, digits and _ (or * for every line).');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, ['forbidden', 'allowed'], true)) {
            throw new ResearchRefused('A rule either forbids or allows words.');
        }
        $phrases = array_values(array_unique(array_filter(array_map(static fn ($p): string => trim((string) $p), (array) ($data['phrases'] ?? [])), static fn (string $p): bool => $p !== '')));
        if ($phrases === [] || count($phrases) > 50 || max(array_map('mb_strlen', $phrases)) > 120) {
            throw new ResearchRefused('A rule names between 1 and 50 phrases, each at most 120 characters.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ResearchRefused('Say why the rule exists.');
        }
        $proof = trim((string) ($data['proof_ref'] ?? ''));
        if ($kind === 'allowed' && $proof === '') {
            throw new ResearchRefused('An allowed claim names the proof it rests on (a lab report, a certificate, a document reference).');
        }

        $rule = ContentClaimsRule::query()->create([
            'business_id' => $business->getKey(),
            'product_line' => $line,
            'kind' => $kind,
            'phrases' => $phrases,
            'reason' => mb_substr($reason, 0, 2000),
            'legal_ref' => ($ref = trim((string) ($data['legal_ref'] ?? ''))) === '' ? null : mb_substr($ref, 0, 120),
            'proof_ref' => $proof === '' ? null : mb_substr($proof, 0, 2000),
            'status' => 'active',
            'created_by' => $by->getKey(),
        ]);
        $this->auditor->record('content.claims_rule.written', $rule,
            after: ['uuid' => $rule->uuid, 'business_id' => $rule->business_id, 'product_line' => $line, 'kind' => $kind, 'phrases' => $phrases, 'legal_ref' => $rule->legal_ref],
            reason: $reason, actorUserId: $by->getKey());

        return $rule;
    }

    public function retire(User $by, ContentClaimsRule $rule, string $reason): ContentClaimsRule
    {
        if (! $this->scope->allows($by, (int) $rule->business_id)) {
            throw new ResearchRefused('That business is outside what you may act on.');
        }
        if ($rule->status !== 'active') {
            throw new ResearchRefused('The rule is already retired.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why the rule is retired.');
        }

        $rule->forceFill(['status' => 'retired', 'retired_by' => $by->getKey(), 'retired_at' => now(), 'retire_reason' => mb_substr($reason, 0, 2000)])->save();
        $this->auditor->record('content.claims_rule.retired', $rule, before: ['status' => 'active'], after: ['status' => 'retired'], reason: $reason, actorUserId: $by->getKey());

        return $rule;
    }
}
