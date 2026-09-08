<?php

namespace Database\Seeders;

use App\Ai\ModelTier;
use App\Ai\Providers\AnthropicProvider;
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
 * ⚠ **No embedding model is registered.** Anthropic does not offer one, and no second provider is
 * configured, so the SPECIALISED tier is deliberately empty. `TaskType::Embedding` will refuse
 * loudly rather than silently embed with something incomparable to the vectors already stored.
 */
class ModelTierSeeder extends Seeder
{
    private const VERIFIED_ON = '2026-09-08';

    public function run(): void
    {
        $chat = ['tool_use', 'json_schema', 'vision', 'long_context'];

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
        ] as $row) {
            AiModel::query()->updateOrCreate(
                [
                    'provider' => AnthropicProvider::PROVIDER,
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
                    'pricing_verified_on' => self::VERIFIED_ON,
                    // ⚠ Only ever set on CREATE. Re-running the seeder must not switch a model back
                    // on that an operator deliberately disabled.
                ] + (AiModel::query()
                    ->where('provider', AnthropicProvider::PROVIDER)
                    ->where('model_id', $row['model_id'])
                    ->where('tier', $row['tier']->value)
                    ->exists()
                    ? []
                    : ['is_active' => true])
            );
        }
    }
}
