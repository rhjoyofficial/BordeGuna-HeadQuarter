<?php

namespace App\Conversation\Language;

use App\Conversation\Language;

/**
 * Whether a proposed customer reply is written in the script the thread requires (docs/92 A.3, A.4).
 *
 * Deterministic and provider-independent: a look at the code points of the HUMAN PROSE, after every
 * token the policy declares as not-prose has been taken out. No model is asked, and the answer for a
 * given text, requirement and policy version is always the same.
 *
 * ⛔ Not a Latin-character count. A Bangla reply that names the order `LLD-20260912-QW3`, a URL, a
 * phone number, an e-mail address, a SKU, or the brand LaLaDia in its canonical spelling is still a
 * Bangla reply, and P3-G2 attempt 4's one miss was a reply whose PROSE was romanised Bangla, not a
 * reply that happened to contain an identifier. So the text is stripped first, category by category
 * and only for the categories the policy allows (`LanguageRequirement::$allowedTokens`), the same way
 * `Language::detect` drops every token carrying a digit, an @ or a scheme before it asks whether
 * Latin words remain; then the words that are left are counted per script.
 *
 * The rule, from the policy: at least one prose word must be in the required script, and the share of
 * prose words in any other script may not exceed the policy's tolerance (`max_other_script_share`,
 * one loanword in five under v1). A reply with no prose at all once the tokens are gone (an order
 * number alone) is valid: nothing in it is in the wrong script.
 *
 * The result carries counts, never the text (docs/92 A.4: no copy of customer content for diagnostics).
 */
final class ScriptPolicyValidator
{
    public const TOKEN_ORDER_ID = 'order_id';

    public const TOKEN_SKU = 'sku';

    public const TOKEN_URL = 'url';

    public const TOKEN_EMAIL = 'email';

    public const TOKEN_PHONE = 'phone';

    public const TOKEN_NUMBER = 'number';

    public const TOKEN_BRAND_NAME = 'brand_name';

    public const TOKEN_PERMITTED_TERM = 'permitted_term';

    public const TOKEN_IDENTIFIER = 'identifier';

    public const TOKEN_CATEGORIES = [
        self::TOKEN_ORDER_ID, self::TOKEN_SKU, self::TOKEN_URL, self::TOKEN_EMAIL, self::TOKEN_PHONE,
        self::TOKEN_NUMBER, self::TOKEN_BRAND_NAME, self::TOKEN_PERMITTED_TERM, self::TOKEN_IDENTIFIER,
    ];

    /** A scheme, a `www.` host, or a bare domain with a path or a known top-level domain. */
    private const URL = '/(?:https?:\/\/|www\.)\S+|\b[a-z0-9.-]+\.(?:com|net|org|bd|garden|cloud|io|co|app|shop|store)(?:\/\S*)?/iu';

    private const EMAIL = '/\S+@\S+\.\S+/u';

    /** A run of seven or more digits with the separators a phone number is written with, in Latin or Bengali numerals. */
    private const PHONE = '/(?<![\p{L}\d\x{09E6}-\x{09EF}])\+?[\d\x{09E6}-\x{09EF}][\d\x{09E6}-\x{09EF}\s\-().]{5,}[\d\x{09E6}-\x{09EF}](?![\p{L}\d\x{09E6}-\x{09EF}])/u';

    /** An order reference: a short upper-case prefix, a hyphen, and digits (`LLD-20260912-QW3`, `BNC-2026-0012`). */
    private const ORDER_ID = '/(?<![A-Za-z0-9-])[A-Z]{2,5}-[A-Z0-9]*\d[A-Z0-9]*(?:-[A-Z0-9]+)*\b/u';

    /** A SKU or code: upper-case letters and digits joined by hyphens, with a digit in it (`PIC-HIL-V1`, `SKU-0042`). */
    private const SKU = '/\b(?=[A-Z0-9-]*\d)[A-Z0-9]+(?:-[A-Z0-9]+)+\b/u';

    /** Any token carrying a digit, a `#`, or an underscore-joined key: a quantity, a price, a date, a tool key. */
    private const IDENTIFIER = '/#\S+|\S*[\d\x{09E6}-\x{09EF}]\S*|\b[a-z]+(?:_[a-z0-9]+)+\b|\b[A-Z]{2,4}\b/u';

    /** A number, with the Taka sign, a comma, a decimal point, a percent sign, in either numeral system. */
    private const NUMBER = '/[৳$]?\s?[\d\x{09E6}-\x{09EF}][\d\x{09E6}-\x{09EF},.]*%?/u';

    private const BENGALI_WORD = '/[\x{0980}-\x{09FF}]+/u';

    /** Two or more Latin letters together, accents included, so a lone `a` or `I` beside a Bangla sentence is not a word in the count. */
    private const LATIN_WORD = '/[A-Za-z\x{00C0}-\x{024F}]{2,}/u';

    public function validate(string $text, LanguageRequirement $requirement): ScriptPolicyResult
    {
        $work = (string) Language::normalise($text);
        $exempted = [];

        // The order matters: an e-mail address holds a domain and a URL may hold a phone-like digit run,
        // so the widest tokens go first and each category only sees what the earlier ones left.
        foreach ($this->patterns($requirement) as $category => $pattern) {
            if (! in_array($category, $requirement->allowedTokens, true)) {
                continue;
            }
            $count = 0;
            $work = (string) preg_replace($pattern, ' ', $work, -1, $count);
            if ($count > 0) {
                $exempted[$category] = $count;
            }
        }

        $bengali = (int) preg_match_all(self::BENGALI_WORD, $work);
        $latin = (int) preg_match_all(self::LATIN_WORD, $work);
        $total = $bengali + $latin;

        [$required, $other] = $requirement->script === LanguageRequirement::SCRIPT_BENGALI ? [$bengali, $latin] : [$latin, $bengali];
        $share = $total === 0 ? 0.0 : round($other / $total, 3);

        $detected = match (true) {
            $total === 0 => ScriptPolicyResult::DETECTED_NONE,
            $bengali > 0 && $latin > 0 => ScriptPolicyResult::DETECTED_MIXED,
            $bengali > 0 => LanguageRequirement::SCRIPT_BENGALI,
            default => LanguageRequirement::SCRIPT_LATIN,
        };

        $reason = match (true) {
            $total === 0 => ScriptPolicyResult::NO_PROSE,
            $required === 0 => ScriptPolicyResult::OTHER_SCRIPT,
            $share > $requirement->maxOtherScriptShare => ScriptPolicyResult::MIXED_SCRIPT,
            default => ScriptPolicyResult::VALID,
        };

        return new ScriptPolicyResult(
            valid: in_array($reason, [ScriptPolicyResult::VALID, ScriptPolicyResult::NO_PROSE], true),
            reason: $reason,
            policyVersion: $requirement->policyVersion,
            requiredLanguage: $requirement->language,
            requiredScript: $requirement->script,
            detectedScript: $detected,
            diagnostics: [
                'prose_words' => $total,
                'required_script_words' => $required,
                'other_script_words' => $other,
                'other_script_share' => $share,
                'max_other_script_share' => $requirement->maxOtherScriptShare,
                'exempted' => $exempted,
            ],
        );
    }

    /**
     * One pattern per token category, in the order they are applied. The brand names and permitted
     * terms come from the policy, quoted, longest first so "Bionic Garden" is taken before "Bionic".
     *
     * @return array<string, string>
     */
    private function patterns(LanguageRequirement $requirement): array
    {
        return [
            self::TOKEN_EMAIL => self::EMAIL,
            self::TOKEN_URL => self::URL,
            self::TOKEN_ORDER_ID => self::ORDER_ID,
            self::TOKEN_SKU => self::SKU,
            self::TOKEN_PHONE => self::PHONE,
            self::TOKEN_BRAND_NAME => $this->literal($requirement->brandNames),
            self::TOKEN_PERMITTED_TERM => $this->literal($requirement->permittedTerms),
            self::TOKEN_IDENTIFIER => self::IDENTIFIER,
            self::TOKEN_NUMBER => self::NUMBER,
        ];
    }

    /**
     * A case-insensitive alternation of literal terms, longest first. A term must stand on its own:
     * not inside a longer Latin word, and not glued to a Bangla letter, so a brand name inflected in
     * Bangla ("লালাদিয়ার") is read as the Bangla word it is.
     *
     * @param  list<string>  $terms
     */
    private function literal(array $terms): string
    {
        $terms = array_values(array_filter(array_map('trim', $terms), static fn (string $t) => $t !== ''));
        if ($terms === []) {
            // A pattern that can never match, so the category counts nothing.
            return '/(?!)/u';
        }
        usort($terms, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return '/(?<![\p{L}\p{M}])(?:'.implode('|', array_map(static fn (string $t) => preg_quote((string) Language::normalise($t), '/'), $terms)).')(?![\p{L}\p{M}])/iu';
    }
}
