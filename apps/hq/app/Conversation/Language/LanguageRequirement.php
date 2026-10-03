<?php

namespace App\Conversation\Language;

/**
 * The reply language and script a Support thread requires, as the runtime resolved them (docs/92 A.2).
 *
 * Two facts, kept apart on purpose: the LANGUAGE the customer is answered in (`bn`, `en`) and the
 * SCRIPT it is written in (`bengali`, `latin`), because a mixed Bangla-and-English message and a
 * romanised-Bangla message are both Bangla and are not written the same way. Both come from the
 * active `LanguagePolicy` applied to the language the conversation core recorded on the thread,
 * never from the model, a prompt example, memory or the operator's wording.
 *
 * Beside the two facts: the policy version that decided them (so a run's evidence says which rules
 * it was held to), the thread tag and the rule that matched, and what the policy exempts from the
 * script check (token categories, brand names, permitted terms, the tolerance for loanwords). The
 * object round-trips through a context block's metadata (`toArray`, `fromArray`) because the
 * simulation world carries the Support context across a rollback as plain data.
 */
final readonly class LanguageRequirement
{
    public const SCRIPT_BENGALI = 'bengali';

    public const SCRIPT_LATIN = 'latin';

    public const SCRIPTS = [self::SCRIPT_BENGALI, self::SCRIPT_LATIN];

    /**
     * @param  list<string>  $allowedTokens  the `ScriptPolicyValidator::TOKEN_*` categories the policy exempts
     * @param  list<string>  $brandNames  approved brand names, in every script, left as they are
     * @param  list<string>  $permittedTerms  English product and platform names the policy permits inside Bangla prose
     */
    public function __construct(
        public string $language,
        public string $script,
        public ?int $policyVersion,
        public ?string $policyDigest,
        public ?string $threadLanguage,
        public ?string $rule,
        public array $allowedTokens,
        public array $brandNames,
        public array $permittedTerms,
        public float $maxOtherScriptShare,
    ) {}

    /** The name of the script as a person (and the model) reads it. */
    public static function scriptName(string $script): string
    {
        return match ($script) {
            self::SCRIPT_BENGALI => 'Bengali script',
            self::SCRIPT_LATIN => 'Latin script',
            default => $script,
        };
    }

    /** The name of the language as a person (and the model) reads it. */
    public static function languageName(string $language): string
    {
        return match ($language) {
            'bn' => 'Bangla',
            'en' => 'English',
            default => $language,
        };
    }

    /** One line a reviewer, an audit row or the model's context can carry. */
    public function describe(): string
    {
        return self::languageName($this->language).' in '.self::scriptName($this->script)
            .($this->policyVersion === null ? '' : " (language policy v{$this->policyVersion}, rule {$this->rule})");
    }

    /**
     * The requirement as plain data: what the context block carries, what the run records, what the
     * audit row holds. Nothing of the customer's in it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'required' => true,
            'language' => $this->language,
            'script' => $this->script,
            'policy_version' => $this->policyVersion,
            'policy_digest' => $this->policyDigest,
            'thread_language' => $this->threadLanguage,
            'rule' => $this->rule,
            'allowed_tokens' => $this->allowedTokens,
            'brand_names' => $this->brandNames,
            'permitted_terms' => $this->permittedTerms,
            'max_other_script_share' => $this->maxOtherScriptShare,
        ];
    }

    /**
     * Back from plain data; null when the data says no requirement was resolved (`required` false,
     * or no language and script in it), so a reader never invents one.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['required'] ?? false) !== true || ! is_string($data['language'] ?? null) || ! in_array($data['script'] ?? null, self::SCRIPTS, true)) {
            return null;
        }

        return new self(
            language: $data['language'],
            script: $data['script'],
            policyVersion: isset($data['policy_version']) ? (int) $data['policy_version'] : null,
            policyDigest: isset($data['policy_digest']) ? (string) $data['policy_digest'] : null,
            threadLanguage: isset($data['thread_language']) ? (string) $data['thread_language'] : null,
            rule: isset($data['rule']) ? (string) $data['rule'] : null,
            allowedTokens: array_values(array_filter((array) ($data['allowed_tokens'] ?? []), 'is_string')),
            brandNames: array_values(array_filter((array) ($data['brand_names'] ?? []), 'is_string')),
            permittedTerms: array_values(array_filter((array) ($data['permitted_terms'] ?? []), 'is_string')),
            maxOtherScriptShare: (float) ($data['max_other_script_share'] ?? 0.0),
        );
    }
}
