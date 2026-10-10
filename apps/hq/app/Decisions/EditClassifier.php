<?php

namespace App\Decisions;

use App\Conversation\Language;

/**
 * Names what a person changed in an AI proposal, from the stored delta alone (docs/111 §4).
 *
 * Deterministic on purpose: every rule is a function of code points, tokens and numbers, so the same
 * row is always labelled the same way and a label can be explained to the owner in one sentence. No
 * model reads the text; nothing here sees `reason`, which a person wrote and which may quote a
 * customer. A record may carry several labels; `PRECEDENCE` picks the one a recurring pattern is
 * keyed on.
 */
final class EditClassifier
{
    public const LANGUAGE_OR_SCRIPT = 'language_or_script';

    public const REGISTER = 'register';

    public const REFUSAL_ADDED = 'refusal_added';

    public const REFUSAL_REMOVED = 'refusal_removed';

    public const FACT_CORRECTED = 'fact_corrected';

    public const SHORTENED = 'shortened';

    public const EXPANDED = 'expanded';

    public const WORDING = 'wording';

    public const REWRITTEN = 'rewritten';

    public const SCOPE_NARROWED = 'scope_narrowed';

    public const SCOPE_WIDENED = 'scope_widened';

    public const TARGET_CHANGED = 'target_changed';

    public const VERDICT_ONLY = 'verdict_only';

    public const UNCLASSIFIED = 'unclassified';

    /** First label wins when a pattern needs one key. */
    public const PRECEDENCE = [
        self::LANGUAGE_OR_SCRIPT, self::REGISTER, self::REFUSAL_ADDED, self::REFUSAL_REMOVED, self::TARGET_CHANGED,
        self::SCOPE_NARROWED, self::SCOPE_WIDENED, self::FACT_CORRECTED, self::SHORTENED, self::EXPANDED,
        self::WORDING, self::REWRITTEN, self::VERDICT_ONLY, self::UNCLASSIFIED,
    ];

    /** English labels for the page and the command; the dictionary carries the Bangla. */
    public const LABELS = [
        self::LANGUAGE_OR_SCRIPT => 'Language or script changed',
        self::REGISTER => 'Register corrected (informal to apni)',
        self::REFUSAL_ADDED => 'Refusal or decline added',
        self::REFUSAL_REMOVED => 'Refusal or decline removed',
        self::FACT_CORRECTED => 'Fact corrected (number, reference, value)',
        self::SHORTENED => 'Shortened',
        self::EXPANDED => 'Expanded',
        self::WORDING => 'Wording',
        self::REWRITTEN => 'Rewritten',
        self::SCOPE_NARROWED => 'Scope narrowed',
        self::SCOPE_WIDENED => 'Scope widened',
        self::TARGET_CHANGED => 'Target changed (customer, thread, order, product)',
        self::VERDICT_ONLY => 'Verdict only (no correction recorded)',
        self::UNCLASSIFIED => 'Unclassified',
    ];

    /** Informal second-person Bangla; the shops' rule is apni, always. */
    private const INFORMAL_PRONOUNS = ['তুমি', 'তোমার', 'তোমাকে', 'তোমাদের', 'তুই', 'তোর', 'তোকে', 'তোদের'];

    /** A closed list of decline markers, Bangla and English. */
    private const REFUSAL_MARKERS = ['দুঃখিত', 'সম্ভব নয়', 'সম্ভব না', 'পারছি না', 'পারব না', 'পারবো না', 'sorry', 'unfortunately', 'cannot', "can't", 'not possible', 'unable'];

    /** Argument keys whose value is prose. */
    private const TEXT_KEYS = ['text', 'message', 'body', 'caption', 'reply', 'note', 'subject', 'content'];

    /** Argument keys that name the counterparty or the thing acted on. */
    private const TARGET_KEYS = ['customer', 'phone', 'recipient', 'to', 'conversation', 'order', 'sku', 'product', 'thread', 'psid', 'email'];

    /**
     * Labels for a reply-text edit (`{from, to}`).
     *
     * @return list<string>
     */
    public function classifyText(?string $from, ?string $to): array
    {
        $from = Language::normalise($from) ?? '';
        $to = Language::normalise($to) ?? '';
        if ($from === $to) {
            return [];
        }

        $labels = [];
        if (Language::detect($from) !== Language::detect($to)) {
            $labels[] = self::LANGUAGE_OR_SCRIPT;
        }
        if (self::containsAny($from, self::INFORMAL_PRONOUNS) && ! self::containsAny($to, self::INFORMAL_PRONOUNS)) {
            $labels[] = self::REGISTER;
        }
        $fromRefuses = self::containsAny($from, self::REFUSAL_MARKERS);
        $toRefuses = self::containsAny($to, self::REFUSAL_MARKERS);
        if ($toRefuses && ! $fromRefuses) {
            $labels[] = self::REFUSAL_ADDED;
        } elseif ($fromRefuses && ! $toRefuses) {
            $labels[] = self::REFUSAL_REMOVED;
        }
        if (self::referenceTokens($from) !== self::referenceTokens($to)) {
            $labels[] = self::FACT_CORRECTED;
        }

        $fromWords = self::words($from);
        $toWords = self::words($to);
        $fromCount = max(1, count($fromWords));
        $toCount = count($toWords);
        if ($toCount <= 0.6 * $fromCount) {
            $labels[] = self::SHORTENED;
        } elseif ($toCount >= 1.5 * $fromCount) {
            $labels[] = self::EXPANDED;
        }

        if ($labels === []) {
            $labels[] = self::jaccard($fromWords, $toWords) >= 0.5 ? self::WORDING : self::REWRITTEN;
        }

        return $labels;
    }

    /**
     * Labels for a tool-argument edit (`{key: {from, to}}`), and the keys that changed.
     *
     * @param  array<string, mixed>  $delta
     * @return array{labels: list<string>, keys: list<string>}
     */
    public function classifyArguments(array $delta): array
    {
        $labels = [];
        $keys = [];
        foreach ($delta as $key => $change) {
            if (! is_array($change) || (! array_key_exists('from', $change) && ! array_key_exists('to', $change))) {
                continue;
            }
            $key = (string) $key;
            $keys[] = $key;
            $from = $change['from'] ?? null;
            $to = $change['to'] ?? null;

            if (self::keyMatches($key, self::TEXT_KEYS) && (is_string($from) || $from === null) && (is_string($to) || $to === null)) {
                array_push($labels, ...$this->classifyText($from, $to));

                continue;
            }
            if (self::keyMatches($key, self::TARGET_KEYS)) {
                $labels[] = self::TARGET_CHANGED;

                continue;
            }
            if ($to === null || $to === [] || $to === '') {
                $labels[] = self::SCOPE_NARROWED;
            } elseif ($from === null || $from === [] || $from === '') {
                $labels[] = self::SCOPE_WIDENED;
            } elseif (is_numeric($from) && is_numeric($to)) {
                $labels[] = (float) $to < (float) $from ? self::SCOPE_NARROWED : self::SCOPE_WIDENED;
            } elseif (is_array($from) && is_array($to) && count($from) !== count($to)) {
                $labels[] = count($to) < count($from) ? self::SCOPE_NARROWED : self::SCOPE_WIDENED;
            } else {
                $labels[] = self::FACT_CORRECTED;
            }
        }

        sort($keys);

        return ['labels' => self::ordered(array_values(array_unique($labels))), 'keys' => $keys];
    }

    /**
     * Labels sorted by precedence.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function ordered(array $labels): array
    {
        $rank = array_flip(self::PRECEDENCE);
        usort($labels, fn (string $a, string $b) => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX));

        return array_values($labels);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return $m[0];
    }

    /**
     * Digits, amounts, order refs, phone numbers, URLs and e-mails: the tokens a fact lives in.
     *
     * @return list<string>
     */
    private static function referenceTokens(string $text): array
    {
        preg_match_all('/\S*(?:\d|@|:\/\/)\S*/u', $text, $m);
        $tokens = array_map(fn (string $t) => mb_strtolower(trim($t, ".,;:!?()[]\"'")), $m[0]);
        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => $t !== '')));
        sort($tokens);

        return $tokens;
    }

    /** @param  list<string>  $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        $lower = mb_strtolower($haystack);
        foreach ($needles as $needle) {
            if (mb_strpos($lower, mb_strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $names */
    private static function keyMatches(string $key, array $names): bool
    {
        $parts = preg_split('/[._\-\s]+/', strtolower($key)) ?: [];
        foreach ($parts as $part) {
            if (in_array($part, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 1.0 : count(array_intersect($a, $b)) / $union;
    }
}
