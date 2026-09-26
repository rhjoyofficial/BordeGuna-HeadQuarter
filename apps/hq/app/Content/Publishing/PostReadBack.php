<?php

namespace App\Content\Publishing;

use Normalizer;

/** A post as the platform returns it on a read, for the read-back and the reconciliation. */
final readonly class PostReadBack
{
    public function __construct(
        public string $postId,
        public ?string $text,
        public ?string $createdAt,
    ) {}

    /** Whether the platform shows the words that were approved (whitespace and NFC aside, nothing else). */
    public function matches(string $approvedText): bool
    {
        return $this->text !== null && self::fold($this->text) === self::fold($approvedText);
    }

    public static function fold(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function toArray(): array
    {
        return ['post_id' => $this->postId, 'text' => $this->text, 'created_at' => $this->createdAt];
    }
}
