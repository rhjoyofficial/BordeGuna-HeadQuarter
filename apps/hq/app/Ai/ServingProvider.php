<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Models\AiModel;

/**
 * Which model provider serves HQ's calls: the provider of the active `model_tiers` rows at the chat
 * tiers (docs/81 C.7).
 *
 * ⚠ The router falls back to a DIFFERENT provider first on an outage (docs/48 J.2). With two providers'
 * rows active, one gate's cases, or one shadow week's answers, could come from two models without
 * anybody having decided it. So "one provider serves" is a fact read from the rows, not assumed, and
 * the things that must be about one model (the gate's bypass check, the readiness row, opening a
 * shadow period) ask this class. Only `hq:ai:provider --apply` changes the answer.
 */
final class ServingProvider
{
    public const SINGLE = 'single';

    public const NONE = 'none';

    public const MIXED = 'mixed';

    /** The provider every install served before a second driver existed, and the default when none serves. */
    public const DEFAULT = AnthropicProvider::PROVIDER;

    /** @return list<string> the providers with an active row at a chat tier, sorted */
    public function active(): array
    {
        $chat = array_map(fn (ModelTier $t) => $t->value, array_filter(ModelTier::cases(), fn (ModelTier $t) => $t->isChat()));

        return AiModel::query()
            ->where('is_active', true)
            ->whereIn('tier', $chat)
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->map(fn ($p) => (string) $p)
            ->values()
            ->all();
    }

    /** The one provider that serves, or null when none or more than one does. */
    public function current(): ?string
    {
        $active = $this->active();

        return count($active) === 1 ? $active[0] : null;
    }

    public function state(): string
    {
        return match (count($this->active())) {
            0 => self::NONE,
            1 => self::SINGLE,
            default => self::MIXED,
        };
    }

    /** In words, for a refusal or a log line. */
    public function describe(): string
    {
        $active = $this->active();

        return match (count($active)) {
            0 => 'no model provider serves: no model_tiers row is active at a chat tier',
            1 => "{$active[0]} serves",
            default => 'more than one model provider serves ('.implode(', ', $active).'); a measurement runs on exactly one: choose it with hq:ai:provider',
        };
    }
}
