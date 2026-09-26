<?php

namespace App\Content;

use Normalizer;

/**
 * What may and may not be said about each product line (docs/85 C.15). Written by the owner, never
 * inferred: a draft for a product line with no policy fails closed.
 *
 * Shape (stored on `content_briefs.claims_policy`):
 *
 *     {"version": "...", "product_lines": {
 *         "mustard_oil": {"label": "...", "forbidden": [{"id": "...", "phrases": ["..."], "reason": "..."}], "allowed": ["..."]},
 *         "*": {"forbidden": [...]}            // applies to every line
 *     }}
 *
 * A phrase matches where a word starts (so "cure" also catches "cures" and "cured", and a Bangla stem
 * catches its inflections), case-insensitively, after Unicode NFC. A phrase in the middle of another
 * word does not match. This is a deterministic filter: it proves a listed phrase is absent, not that
 * the text makes no claim the owner forgot to list.
 */
final readonly class ClaimsPolicy
{
    /**
     * @param  array<string, array{label?: string, forbidden?: list<array{id: string, phrases: list<string>, reason?: string}>, allowed?: list<string>}>  $lines
     * @param  list<string>  $bannedWords
     */
    public function __construct(
        public string $version,
        public array $lines,
        public array $bannedWords = [],
    ) {}

    public static function fromArray(array $policy, array $bannedWords = []): self
    {
        return new self(
            (string) ($policy['version'] ?? 'unversioned'),
            is_array($policy['product_lines'] ?? null) ? $policy['product_lines'] : [],
            array_values(array_filter(array_map('strval', $bannedWords))),
        );
    }

    public function covers(string $productLine): bool
    {
        return isset($this->lines[$productLine]);
    }

    /**
     * @param  list<string>  $productLines
     * @return list<array{product_line: string, rule: string, phrase: string, reason: ?string}>
     */
    public function violations(string $text, array $productLines): array
    {
        $haystack = self::fold($text);
        $found = [];

        foreach (array_values(array_unique(array_merge(['*'], $productLines))) as $line) {
            foreach ($this->lines[$line]['forbidden'] ?? [] as $rule) {
                foreach ((array) ($rule['phrases'] ?? []) as $phrase) {
                    if (self::contains($haystack, self::fold((string) $phrase))) {
                        $found[] = [
                            'product_line' => $line,
                            'rule' => (string) ($rule['id'] ?? 'unnamed'),
                            'phrase' => (string) $phrase,
                            'reason' => isset($rule['reason']) ? (string) $rule['reason'] : null,
                        ];
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    public function bannedWordsIn(string $text): array
    {
        $haystack = self::fold($text);

        return array_values(array_filter($this->bannedWords, static fn (string $w): bool => self::contains($haystack, self::fold($w))));
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{M}\p{N}])'.preg_quote($needle, '/').'/u', $haystack) === 1;
    }

    private static function fold(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
