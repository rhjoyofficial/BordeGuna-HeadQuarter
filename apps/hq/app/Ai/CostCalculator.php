<?php

namespace App\Ai;

use App\Models\AiModel;

/**
 * Turns token counts into money, using the prices on the model row that actually ran.
 *
 * ⚠ **Cached input is not billed at the input price.** A prompt-cache read costs a fraction of the
 * base rate, and a system that bills cache reads at full price reports a cost the invoice will never
 * match. `cached_tokens` is therefore removed from the billable input count and charged separately.
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
     * @return string a decimal string, never a float
     */
    public function estimate(AiModel $model, int $inputTokens, int $outputTokens, int $cachedTokens = 0): string
    {
        $cached = max(0, min($cachedTokens, $inputTokens));
        $freshInput = $inputTokens - $cached;

        $cost = $this->lineItem((string) $freshInput, (string) $model->input_price_per_mtok);
        $cost = bcadd($cost, $this->lineItem((string) $outputTokens, (string) $model->output_price_per_mtok), self::SCALE);

        // A model with no recorded cache price falls back to the base input price rather than to
        // zero: guessing "free" understates every bill, and understating is the dangerous direction.
        $cachePrice = $model->cache_read_price_per_mtok !== null
            ? (string) $model->cache_read_price_per_mtok
            : (string) $model->input_price_per_mtok;

        return bcadd($cost, $this->lineItem((string) $cached, $cachePrice), self::SCALE);
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
