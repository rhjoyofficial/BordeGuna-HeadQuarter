<?php

namespace App\Scheduling\Nl;

/**
 * The shape a model must return when it reads a sentence as a scheduled job (docs/48 L.1, docs/78 B.3).
 *
 * ⚠ Inside the same narrow subset as `AgentResponseSchema`, for the same reason: one schema goes to the
 * provider's strict output mode and to `ArgumentValidator`, and only `type` and `enum` per property,
 * with every property required and `additionalProperties: false`, mean the same thing to both. So
 * there are no nullable fields (an empty string or zero means "not this") and the tool arguments come
 * back as a JSON string, because a free-form object cannot be declared under strict output.
 *
 * The model only proposes. `JobSpecValidator` decides whether a job exists.
 */
final class JobSpecSchema
{
    public const OUTCOME_JOB = 'job';

    public const OUTCOME_QUESTION = 'question';

    public const KINDS = ['once', 'cron', 'interval', 'event', 'none'];

    public const VERSION = 'scheduler.nl.v1';

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['outcome', 'question', 'name', 'kind', 'tool_key', 'cron_expression', 'run_at_local',
                'interval_minutes', 'event_type', 'timezone', 'business', 'store', 'payload_json'],
            'properties' => [
                // `question` when anything needed is missing or unclear; then `question` holds ONE question.
                'outcome' => ['type' => 'string', 'enum' => [self::OUTCOME_JOB, self::OUTCOME_QUESTION]],
                'question' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => self::KINDS],
                // Exactly a key from the list the prompt gives; never a new one.
                'tool_key' => ['type' => 'string'],
                // Five fields, read in `timezone`; empty unless kind is cron.
                'cron_expression' => ['type' => 'string'],
                // "YYYY-MM-DD HH:MM" in `timezone`; empty unless kind is once.
                'run_at_local' => ['type' => 'string'],
                // Zero unless kind is interval.
                'interval_minutes' => ['type' => 'integer'],
                // Empty unless kind is event.
                'event_type' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                // As the person named them, or empty; resolved to real rows by HQ, never invented.
                'business' => ['type' => 'string'],
                'store' => ['type' => 'string'],
                // A JSON object with the tool's arguments, "{}" when it takes none.
                'payload_json' => ['type' => 'string'],
            ],
        ];
    }

    public const PERMITTED_ROOT_KEYWORDS = ['type', 'properties', 'required', 'additionalProperties'];

    public const PERMITTED_PROPERTY_KEYWORDS = ['type', 'enum'];
}
