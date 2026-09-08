<?php

namespace App\Ai;

/**
 * What the caller is trying to do. The router turns this into a tier.
 *
 * A task is named for the WORK, not the quality wanted, so that raising or lowering the tier for a
 * whole class of work is one change here rather than an audit of every call site.
 */
enum TaskType: string
{
    case Classification = 'classification';
    case Extraction = 'extraction';
    case Summarisation = 'summarisation';
    case SchemaRepair = 'schema_repair';
    case NlToJobSpec = 'nl_to_job_spec';
    case AnalyticsNarration = 'analytics_narration';
    case OperatorQuestion = 'operator_question';
    case BriefingSection = 'briefing_section';
    case BriefingNarrative = 'briefing_narrative';
    case Reasoning = 'reasoning';
    case Embedding = 'embedding';
    case Diagnostic = 'diagnostic';

    /** The tier this task asks for before any budget or health adjustment. */
    public function defaultTier(): ModelTier
    {
        return match ($this) {
            self::Classification, self::Extraction, self::Summarisation,
            self::SchemaRepair, self::NlToJobSpec, self::Diagnostic => ModelTier::Low,

            self::AnalyticsNarration, self::OperatorQuestion, self::BriefingSection => ModelTier::Medium,

            self::BriefingNarrative, self::Reasoning => ModelTier::High,

            self::Embedding => ModelTier::Specialised,
        };
    }

    /**
     * May this task run on a weaker tier when the one it asked for is unavailable or over budget?
     *
     * ⚠ Schema repair is NOT degradable: it exists to fix output that a model already got wrong, and
     * a weaker model is the last thing that should be asked to do it. An embedding is not degradable
     * either, because a vector from a different model is not comparable with the ones already stored.
     */
    public function degradable(): bool
    {
        return match ($this) {
            self::SchemaRepair, self::Embedding => false,
            default => true,
        };
    }

    /** Whether the caller expects a JSON object matching a schema rather than prose. */
    public function structured(): bool
    {
        return match ($this) {
            self::Classification, self::Extraction, self::NlToJobSpec, self::SchemaRepair => true,
            default => false,
        };
    }
}
