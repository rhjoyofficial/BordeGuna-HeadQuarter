<?php

namespace App\Ai;

/**
 * What a call needs from a model, expressed as a capability band rather than a model name.
 *
 * ⚠ **Business code names a tier, never a model** (ADR-019). Model ids live in `model_tiers` rows,
 * so a price change, a retirement or a better model is a row edit rather than a deploy. A grep test
 * enforces that no model id appears in `app/` outside the driver layer.
 *
 * The bands are deliberately coarse. A finer scale would invite callers to reason about which exact
 * model they want, which is the habit this whole design exists to prevent.
 */
enum ModelTier: string
{
    /** Classification, extraction, intent, schema repair: cheap, fast, high volume. */
    case Low = 'low';

    /** Analytics narration, ordinary operator questions, briefing sections. */
    case Medium = 'medium';

    /** The daily briefing narrative, multi-step reasoning, "why did this happen". */
    case High = 'high';

    /** Embeddings and anything else that is not a chat completion. */
    case Specialised = 'specialised';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'low: cheap and fast',
            self::Medium => 'medium: the working tier',
            self::High => 'high: strongest reasoning',
            self::Specialised => 'specialised: embeddings and similar',
        };
    }

    /**
     * The tier to fall back to when this one cannot be served and the task tolerates it.
     *
     * ⚠ Degrading DOWN is a cost and quality decision, never a safety one: a lower tier is cheaper
     * and weaker, so it is only ever chosen when the caller said the task is degradable, and the
     * degradation is written onto the run so the output can say it ran degraded.
     */
    public function degradesTo(): ?self
    {
        return match ($this) {
            self::High => self::Medium,
            self::Medium => self::Low,
            // Nothing sensible below `low`, and an embedding cannot be approximated by a chat model.
            self::Low, self::Specialised => null,
        };
    }

    public function isChat(): bool
    {
        return $this !== self::Specialised;
    }
}
