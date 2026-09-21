<?php

namespace Database\Seeders;

use App\Ai\ModelTier;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\ServingProvider;
use App\Models\AiModel;
use Illuminate\Database\Seeder;

/**
 * The models HQ may choose from, with the prices it will be billed at.
 *
 * ⚠ **Every id and price below was read from the provider's own pricing and models pages on
 * 8 September 2026**, per ADR-019, not recalled. `pricing_verified_on` records that date so a future
 * reader can tell how stale these numbers are without having to trust the row.
 *
 * ⚠ This seeder is **idempotent and non-destructive**: it upserts by (provider, model_id, tier) and
 * never deactivates a row somebody added by hand. A seeder that reset the table would silently undo
 * an operator's decision to prefer one model over another.
 *
 * Tier assignment is a judgement about work, not a ranking of models:
 *   LOW    Haiku 4.5  - classification, extraction, schema repair. Cheap, fast, high volume.
 *   MEDIUM Sonnet 5   - the working tier: narration, operator questions, briefing sections.
 *   HIGH   Opus 5     - the briefing narrative and multi-step reasoning.
 *
 * Opus 5 is also registered at MEDIUM with a worse priority, so that if Sonnet is unavailable the
 * medium tier degrades UPWARD in capability rather than downward, at a higher price. Paying more to
 * keep working is the right trade for a tier the morning briefing depends on.
 *
 * ⚠ **No embedding model is registered.** Anthropic does not offer one, and the OpenAI rows below are
 * chat models only, so the SPECIALISED tier is deliberately empty. `TaskType::Embedding` will refuse
 * loudly rather than silently embed with something incomparable to the vectors already stored.
 *
 * ## OpenAI (docs/81 C.7, the owner's decision of 21 September)
 *
 * ⚠ **Read from OpenAI's pricing and model pages on 21 September 2026**, standard tier, short context:
 *   LOW    GPT-5.6 Luna  - the nano-class model of the family.
 *   MEDIUM GPT-5.6 Terra - "roughly corresponds to the mini model tier"; priced like Sonnet 5 on input.
 *   HIGH   GPT-5.6 Sol   - the flagship; also at MEDIUM with a worse priority, the same degrade-upward
 *                          fallback Opus gives the Anthropic rows.
 * GPT-5.6 BILLS CACHE WRITES at 1.25x the input rate (the page's "cache writes" column), so the rows
 * carry that price: leaving it null would price writes at the plain input rate and under-report.
 * `context_window` is 272,000, the short-context band: above it OpenAI bills the WHOLE request at 2x
 * input and 1.5x output, which `CostCalculator` does not model, so HQ refuses a bigger prompt rather
 * than record a cost the invoice will not match. The models themselves take 922,000 input tokens.
 *
 * ## Which rows are switched on
 *
 * ⚠ A new row is created ACTIVE only when its provider already serves, or, when nothing serves yet,
 * only for Anthropic, the provider every existing install runs on. So seeding OpenAI on production
 * switches nothing on, a fresh install serves exactly what it served before, and a later model added
 * for the serving provider joins it. Moving traffic between providers is `hq:ai:provider --apply
 * --by=<person>`, never a seeder side effect.
 */
class ModelTierSeeder extends Seeder
{
    private const VERIFIED_ON = '2026-09-08';

    private const OPENAI_VERIFIED_ON = '2026-09-21';

    public function run(): void
    {
        $chat = ['tool_use', 'json_schema', 'vision', 'long_context'];
        $serving = app(ServingProvider::class)->current()
            ?? (app(ServingProvider::class)->active() === [] ? ServingProvider::DEFAULT : null);

        foreach ([
            [
                'tier' => ModelTier::Low,
                'model_id' => 'claude-haiku-4-5-20251001',
                'display_name' => 'Claude Haiku 4.5',
                'context_window' => 200_000,
                'max_output_tokens' => 64_000,
                'input' => '1.0000', 'output' => '5.0000', 'cache_read' => '0.1000', 'cache_write' => '1.2500',
                'priority' => 10,
                'notes' => 'Fastest, near-frontier. The default for classification, extraction and schema repair.',
            ],
            [
                'tier' => ModelTier::Medium,
                'model_id' => 'claude-sonnet-5',
                'display_name' => 'Claude Sonnet 5',
                'context_window' => 1_000_000,
                'max_output_tokens' => 128_000,
                'input' => '2.0000', 'output' => '10.0000', 'cache_read' => '0.2000', 'cache_write' => '2.5000',
                'priority' => 10,
                'notes' => 'The working tier: best balance of speed and intelligence.',
            ],
            [
                'tier' => ModelTier::Medium,
                'model_id' => 'claude-opus-5',
                'display_name' => 'Claude Opus 5',
                'context_window' => 1_000_000,
                'max_output_tokens' => 128_000,
                'input' => '5.0000', 'output' => '25.0000', 'cache_read' => '0.5000', 'cache_write' => '6.2500',
                'priority' => 90,
                'notes' => 'Medium-tier fallback. Costs more than Sonnet, so it is chosen only when Sonnet cannot serve the call.',
            ],
            [
                'tier' => ModelTier::High,
                'model_id' => 'claude-opus-5',
                'display_name' => 'Claude Opus 5',
                'context_window' => 1_000_000,
                'max_output_tokens' => 128_000,
                'input' => '5.0000', 'output' => '25.0000', 'cache_read' => '0.5000', 'cache_write' => '6.2500',
                'priority' => 10,
                'notes' => 'The briefing narrative and multi-step reasoning.',
            ],
            [
                'provider' => OpenAiProvider::PROVIDER,
                'verified_on' => self::OPENAI_VERIFIED_ON,
                'tier' => ModelTier::Low,
                'model_id' => 'gpt-5.6-luna',
                'display_name' => 'GPT-5.6 Luna',
                'context_window' => 272_000,
                'max_output_tokens' => 128_000,
                'input' => '0.2000', 'output' => '1.2000', 'cache_read' => '0.0200', 'cache_write' => '0.2500',
                'priority' => 10,
                'notes' => 'OpenAI, cost-sensitive high volume: classification, extraction, schema repair. Registered at the 272K short-context band; above it the whole request bills at 2x input and 1.5x output.',
            ],
            [
                'provider' => OpenAiProvider::PROVIDER,
                'verified_on' => self::OPENAI_VERIFIED_ON,
                'tier' => ModelTier::Medium,
                'model_id' => 'gpt-5.6-terra',
                'display_name' => 'GPT-5.6 Terra',
                'context_window' => 272_000,
                'max_output_tokens' => 128_000,
                'input' => '2.0000', 'output' => '12.0000', 'cache_read' => '0.2000', 'cache_write' => '2.5000',
                'priority' => 10,
                'notes' => 'OpenAI working tier (OpenAI: roughly the mini tier of earlier GPT-5 families). The agent turns run here. Reasoning effort is hq.ai.openai.reasoning_effort.',
            ],
            [
                'provider' => OpenAiProvider::PROVIDER,
                'verified_on' => self::OPENAI_VERIFIED_ON,
                'tier' => ModelTier::Medium,
                'model_id' => 'gpt-5.6-sol',
                'display_name' => 'GPT-5.6 Sol',
                'context_window' => 272_000,
                'max_output_tokens' => 128_000,
                'input' => '4.0000', 'output' => '20.0000', 'cache_read' => '0.4000', 'cache_write' => '5.0000',
                'priority' => 90,
                'notes' => 'OpenAI medium-tier fallback, chosen only when Terra cannot serve. Promotional price, OpenAI says at least through November 21, 2026; normally 5.00 in and 30.00 out: re-read the pricing page then.',
            ],
            [
                'provider' => OpenAiProvider::PROVIDER,
                'verified_on' => self::OPENAI_VERIFIED_ON,
                'tier' => ModelTier::High,
                'model_id' => 'gpt-5.6-sol',
                'display_name' => 'GPT-5.6 Sol',
                'context_window' => 272_000,
                'max_output_tokens' => 128_000,
                'input' => '4.0000', 'output' => '20.0000', 'cache_read' => '0.4000', 'cache_write' => '5.0000',
                'priority' => 10,
                'notes' => 'OpenAI flagship: the briefing narrative and multi-step reasoning. Promotional price, OpenAI says at least through November 21, 2026; normally 5.00 in and 30.00 out: re-read the pricing page then.',
            ],
        ] as $row) {
            $provider = $row['provider'] ?? AnthropicProvider::PROVIDER;

            AiModel::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'model_id' => $row['model_id'],
                    'tier' => $row['tier']->value,
                ],
                [
                    'display_name' => $row['display_name'],
                    'context_window' => $row['context_window'],
                    'max_output_tokens' => $row['max_output_tokens'],
                    'capabilities' => $chat,
                    'input_price_per_mtok' => $row['input'],
                    'output_price_per_mtok' => $row['output'],
                    'cache_read_price_per_mtok' => $row['cache_read'],
                    'cache_write_price_per_mtok' => $row['cache_write'],
                    'priority' => $row['priority'],
                    'notes' => $row['notes'],
                    'pricing_verified_on' => $row['verified_on'] ?? self::VERIFIED_ON,
                    // ⚠ Only ever set on CREATE. Re-running the seeder must not switch a model back
                    // on that an operator deliberately disabled, and a new row is active only when its
                    // provider is the one that serves (see the class comment).
                ] + (AiModel::query()
                    ->where('provider', $provider)
                    ->where('model_id', $row['model_id'])
                    ->where('tier', $row['tier']->value)
                    ->exists()
                    ? []
                    : ['is_active' => $provider === $serving])
            );
        }
    }
}
