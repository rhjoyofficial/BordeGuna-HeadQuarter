<?php

namespace App\Ai\Providers;

/**
 * What a provider returned, normalised.
 *
 * ⚠ Token counts come from the PROVIDER's own usage report, never from a local estimate. A local
 * token count is a guess, and a cost built on a guess cannot be reconciled against an invoice, which
 * is the entire reason `ai_runs` separates `estimated_cost_usd` from `actual_cost_usd`.
 *
 * ⚠ `cachedTokens` and `cacheWriteTokens` are both SUBSETS of `inputTokens`, not additions to it,
 * and they never overlap: a token is either read from the cache, written to it, or neither. Every
 * consumer must treat them that way or cached calls are billed twice.
 */
final readonly class ModelResponse
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedTokens,
        public int $latencyMs,
        public string $stopReason,
        /**
         * Input tokens WRITTEN to the prompt cache on this call. Billed at a premium over the base
         * input rate, so they cannot be lumped in with ordinary input without under-reporting cost.
         */
        public int $cacheWriteTokens = 0,
        /** Raw usage as reported, kept so a later reconciliation can see what was actually said. */
        public array $rawUsage = [],
    ) {}

    /** @return array<string, mixed>|null the parsed object, or null when the text is not a JSON object */
    public function json(): ?array
    {
        $trimmed = trim($this->text);

        // Providers sometimes wrap JSON in a fenced block despite being asked not to. Unwrapping it
        // here is not leniency about the contract; it is refusing to spend a repair call on a
        // difference that carries no meaning.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    public function truncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
