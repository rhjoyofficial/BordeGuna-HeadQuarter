<?php

namespace App\Observe\Retention;

/**
 * What retention may touch, and what it must never touch (docs/78 B.2, decision V.7).
 *
 * ⛔ The permanent classes are not configuration. They are listed here only so a test can prove that
 * none of them is ever a retention target; nothing in this namespace can be pointed at them, because
 * the detail tables are a closed set of three definitions written below, each with the SQL that keeps
 * a row forever when anything permanent still points at it.
 *
 * **Why agent detail is kept whole.** Every tool call and model call made for an agent run is the
 * evidence an autonomy decision rests on (ChatGPT, 16 September: "do not silently destroy historical
 * evaluation evidence needed for autonomy decisions"). So retention prunes only the high-volume
 * unattended detail: scheduled polls and reconciliations, their connector calls, and model calls that
 * belong to no agent run. A row is kept forever if an agent, an approval, an evaluation result, a
 * conversation message, a consent event or a stored briefing refers to it, if it shares a
 * correlation id with an agent run, or if it never finished.
 */
final class RetentionPolicy
{
    public const DETAIL_TABLES = ['tool_calls', 'ai_runs', 'connector_calls'];

    public const EVENT_PAYLOADS = 'events.payload';

    /**
     * The permanent evidence classes: kept forever. `audit_logs` and `decision_records` are V.7's own
     * words; the rest are the record autonomy, approval, evaluation, shadow and consent decisions rest on.
     */
    public const PERMANENT = [
        'audit_logs',
        'decision_records',
        'agent_runs',
        'approvals',
        'autonomy_grants',
        'evaluation_suites',
        'evaluation_cases',
        'evaluation_runs',
        'evaluation_results',
        'shadow_periods',
        'shadow_probe_runs',
        'shadow_evidence_days',
        'shadow_comparisons',
        'consent_events',
        'consent_state',
        'customer_merges',
        // docs/80 C.1: a stored briefing is the evidence a decision record may later point at ("he
        // acted on this recommendation", docs/48 M.4), bundle and all. It is never pruned.
        'briefings',
    ];

    /**
     * Not governed by this policy at all. Conversation and memory retention is a personal-data decision
     * of its own (docs/53 flagged it) that nobody has taken yet, and Ask HQ's operator conversations
     * point at agent runs that are kept forever, so this pass leaves those rows alone rather than
     * inventing a period for them. Retention's own lineage is here too: it is never pruned
     * by the thing whose history it is.
     */
    public const UNGOVERNED = [
        'conversations',
        'conversation_messages',
        'conversation_message_attachments',
        'memories',
        'ask_conversations',
        'ask_messages',
        'retention_runs',
        'retention_watermarks',
        'daily_aggregates',
    ];

    /** @return list<string> every table no statement in this namespace may delete from */
    public static function neverTargeted(): array
    {
        return [...self::PERMANENT, ...self::UNGOVERNED];
    }

    /** The marker a compacted event payload carries instead of its content. */
    public const DIGEST_KEY = '_retention';

    public function version(): string
    {
        return (string) config('hq.retention.version', 'retention.v1');
    }

    public function keepDays(string $table): int
    {
        if (! in_array($table, self::DETAIL_TABLES, true)) {
            throw new \InvalidArgumentException("[{$table}] is not a retention target.");
        }

        return max(1, (int) config("hq.retention.detail_keep_days.{$table}", 180));
    }

    public function eventPayloadKeepDays(): int
    {
        return max(1, (int) config('hq.retention.event_payload_keep_days', 365));
    }

    public function batchSize(): int
    {
        return max(1, (int) config('hq.retention.batch_size', 500));
    }

    public function maxRowsPerTable(): int
    {
        return max(1, (int) config('hq.retention.max_rows_per_table', 50000));
    }

    public function maxDaysPerTable(): int
    {
        return max(1, (int) config('hq.retention.max_days_per_table', 31));
    }

    public function maxSeconds(): int
    {
        return max(1, (int) config('hq.retention.max_seconds', 600));
    }

    /** The policy as a record, stored on every run. */
    public function snapshot(): array
    {
        return [
            'version' => $this->version(),
            'detail_keep_days' => array_combine(self::DETAIL_TABLES, array_map($this->keepDays(...), self::DETAIL_TABLES)),
            'event_payload_keep_days' => $this->eventPayloadKeepDays(),
            'event_payload_scope' => 'processed events only; dead, failed and pending events keep their payload',
            'batch_size' => $this->batchSize(),
            'max_rows_per_table' => $this->maxRowsPerTable(),
            'max_days_per_table' => $this->maxDaysPerTable(),
            'max_seconds' => $this->maxSeconds(),
            'timezone' => $this->timezone(),
        ];
    }

    public function timezone(): string
    {
        return (string) config('hq.timezone', 'Asia/Dhaka');
    }

    /** @return list<DetailTable> */
    public function detailTables(): array
    {
        return array_map($this->detail(...), self::DETAIL_TABLES);
    }

    public function detail(string $table): DetailTable
    {
        return match ($table) {
            'tool_calls' => new DetailTable(
                table: 'tool_calls',
                keepDays: $this->keepDays('tool_calls'),
                dimensions: ['tool_key', 'risk_tier', 'caller_kind', 'actor_type', 'business_id', 'status',
                    'policy_result', 'action_class', 'failure_class', 'outcome_class', 'replayed'],
                durationColumn: 'duration_ms',
                costExpression: null,
                tokensInColumn: null,
                tokensOutColumn: null,
                extraMeasures: ['retried' => 'count(*) filter (where t.attempt > 1)'],
                keepCondition: <<<'SQL'
                    t.agent_key IS NOT NULL
                    OR t.ai_run_id IS NOT NULL
                    OR t.approval_id IS NOT NULL
                    OR t.status IN ('started', 'uncertain', 'awaiting_approval')
                    OR EXISTS (SELECT 1 FROM approvals a WHERE a.requesting_tool_call_id = t.id)
                    OR EXISTS (SELECT 1 FROM approvals a WHERE a.resulting_tool_call_id = t.id)
                    OR EXISTS (SELECT 1 FROM conversation_messages m WHERE m.tool_call_id = t.id)
                    OR EXISTS (SELECT 1 FROM consent_events c WHERE c.tool_call_id = t.id)
                    OR (t.correlation_id IS NOT NULL AND EXISTS (SELECT 1 FROM agent_runs r WHERE r.correlation_id = t.correlation_id::text))
                    SQL,
            ),
            'ai_runs' => new DetailTable(
                table: 'ai_runs',
                keepDays: $this->keepDays('ai_runs'),
                dimensions: ['task', 'provider', 'model', 'model_tier', 'business_id', 'status', 'cost_source',
                    'degraded_from_tier', 'stop_reason'],
                durationColumn: 'latency_ms',
                costExpression: 'COALESCE(t.actual_cost_usd, t.estimated_cost_usd, 0)',
                tokensInColumn: 'input_tokens',
                tokensOutColumn: 'output_tokens',
                extraMeasures: [
                    'cached_tokens' => 'sum(t.cached_tokens)',
                    'cache_write_tokens' => 'sum(t.cache_write_tokens)',
                    'estimated_cost_usd' => 'sum(t.estimated_cost_usd)',
                    'actual_cost_usd' => 'sum(t.actual_cost_usd)',
                ],
                keepCondition: <<<'SQL'
                    t.agent_key IS NOT NULL
                    OR t.status IN ('pending', 'started')
                    OR EXISTS (SELECT 1 FROM evaluation_results e WHERE e.ai_run_id = t.id)
                    OR EXISTS (SELECT 1 FROM approvals a WHERE a.ai_run_id = t.id)
                    OR EXISTS (SELECT 1 FROM conversation_messages m WHERE m.ai_run_id = t.id)
                    OR EXISTS (SELECT 1 FROM briefings b WHERE b.ai_run_id = t.id)
                    OR EXISTS (SELECT 1 FROM tool_calls c WHERE c.ai_run_id = t.id)
                    OR (t.correlation_id IS NOT NULL AND EXISTS (SELECT 1 FROM agent_runs r WHERE r.correlation_id = t.correlation_id::text))
                    SQL,
            ),
            'connector_calls' => new DetailTable(
                table: 'connector_calls',
                keepDays: $this->keepDays('connector_calls'),
                dimensions: ['connector', 'operation', 'status', 'response_status'],
                durationColumn: 'duration_ms',
                costExpression: null,
                tokensInColumn: null,
                tokensOutColumn: null,
                extraMeasures: [],
                keepCondition: <<<'SQL'
                    t.status = 'uncertain'
                    OR (t.idempotency_key IS NOT NULL AND EXISTS (SELECT 1 FROM tool_calls c WHERE c.idempotency_key = t.idempotency_key))
                    OR (t.correlation_id IS NOT NULL AND EXISTS (SELECT 1 FROM agent_runs r WHERE r.correlation_id = t.correlation_id))
                    SQL,
            ),
            default => throw new \InvalidArgumentException("[{$table}] is not a retention target."),
        };
    }

    /** True for a payload that retention has already reduced to its digest. */
    public static function isCompacted(mixed $payload): bool
    {
        return is_array($payload) && array_key_exists(self::DIGEST_KEY, $payload);
    }
}
