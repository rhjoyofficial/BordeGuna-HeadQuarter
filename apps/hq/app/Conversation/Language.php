<?php

namespace App\Conversation;

/**
 * Which script a customer wrote in (docs/72 §B).
 *
 * Not a language model: a look at the code points. Bengali letters mean `bn`; Latin letters alone
 * mean `en`; both together mean `banglish`, the romanised Bangla most Messenger customers actually
 * type. The tag tells the support agent which register to answer in and tells the inbox which
 * threads a Bangla-only operator can take. Nothing else reads it.
 */
final class Language
{
    public const BANGLA = 'bn';

    public const ENGLISH = 'en';

    public const BANGLISH = 'banglish';

    public static function detect(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $bengali = preg_match('/[\x{0980}-\x{09FF}]/u', $text) === 1;

        // An order number, a SKU, a URL or an e-mail is not English: every token carrying a digit,
        // an @ or a scheme is dropped before asking whether Latin words remain. "আমার অর্ডার
        // LLD-20260912-QW3 কোথায়?" is Bangla; "Order LLD-20260912-QW3 এখনো আসেনি, please check" is both.
        $words = preg_replace('/\S*(?:\d|@|:\/\/)\S*/u', ' ', $text) ?? $text;
        $latin = preg_match('/[A-Za-z]{2,}/u', $words) === 1;

        return match (true) {
            $bengali && $latin => self::BANGLISH,
            $bengali => self::BANGLA,
            $latin => self::ENGLISH,
            default => null,
        };
    }

    /** NFC first, so the same Bangla word is one byte sequence wherever it was typed. */
    public static function normalise(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalised = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;

        return is_string($normalised) ? $normalised : $text;
    }
}
