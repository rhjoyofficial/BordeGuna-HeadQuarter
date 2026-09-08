<?php

namespace App\Ai;

use App\Models\AiModel;

/**
 * Turns token counts into money, using the prices on the model row that actually ran.
 *
 * ⚠ **Cached input is not billed at the input price, in EITHER direction.** A prompt-cache read
 * costs a fraction of the base rate; a cache WRITE costs a premium over it (1.25x at the five-minute
 * TTL). A system that bills reads at full price reports a cost the invoice will never match - and so
 * does one that bills writes at the base rate, which is the same error pointing the other way and
 * the more dangerous one, because it UNDER-reports. Both are removed from the billable input count
 * and charged at their own rates.
 *
 * ⚠ Arithmetic is done in string decimals via `bcmath`, not floats. Per-million-token prices multiplied
 * by token counts and summed across thousands of calls is exactly the shape where float error becomes
 * visible money.
 */
final class CostCalculator
{
    /** USD is stored to 6 decimal places, which is a hundredth of a cent per call. */
    private const SCALE = 6;

    private const PER_MILLION = '1000000';

    /**
     * @param  int  $cachedTokens  input tokens served from the prompt cache, a SUBSET of $inputTokens
     * @param  int  $cacheWriteTokens  input tokens written to the cache, also a SUBSET of $inputTokens
     * @return string a decimal string, never a float
     */
    public function estimate(AiModel $model, int $inputTokens, int $outputTokens, int $cachedTokens = 0, int $cacheWriteTokens = 0): string
    {
        // Clamped and then subtracted in order, so the three portions can never sum past the input
        // total however a provider reports them. Billing more tokens than were sent is the one
        // arithmetic error that would survive review by looking conservative.
        $cached = max(0, min($cachedTokens, $inputTokens));
        $written = max(0, min($cacheWriteTokens, $inputTokens - $cached));
        $freshInput = $inputTokens - $cached - $written;

        $cost = $this->lineItem((string) $freshInput, (string) $model->input_price_per_mtok);
        $cost = bcadd($cost, $this->lineItem((string) $outputTokens, (string) $model->output_price_per_mtok), self::SCALE);
        $cost = bcadd($cost, $this->lineItem((string) $cached, $this->cachePrice($model, 'cache_read_price_per_mtok')), self::SCALE);

        return bcadd($cost, $this->lineItem((string) $written, $this->cachePrice($model, 'cache_write_price_per_mtok')), self::SCALE);
    }

    /**
     * A model with no recorded cache price falls back to the base input price rather than to zero:
     * guessing "free" understates every bill, and understating is the dangerous direction.
     */
    private function cachePrice(AiModel $model, string $column): string
    {
        return $model->{$column} !== null
            ? (string) $model->{$column}
            : (string) $model->input_price_per_mtok;
    }

    /** tokens x price-per-million / 1,000,000 */
    private function lineItem(string $tokens, string $pricePerMillion): string
    {
        if ($tokens === '0' || $pricePerMillion === '') {
            return bcadd('0', '0', self::SCALE);
        }

        return bcdiv(bcmul($tokens, $pricePerMillion, self::SCALE), self::PER_MILLION, self::SCALE);
    }

    /** For an operator, not for accounting: accounting reads the stored decimal. */
    public function format(string $usd): string
    {
        return '$'.number_format((float) $usd, 4);
    }
}
