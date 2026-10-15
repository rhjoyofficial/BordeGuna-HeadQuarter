<?php

namespace App\Outcomes;

/**
 * The HQ actions an outcome can be credited to (docs/107 section 4.3). Each is one typed foreign-key
 * column on `outcome_attributions`, and a CHECK makes exactly that column the one that is set (D6).
 * Adding a kind of action is an additive migration: one nullable column and the CHECK replaced.
 *
 * A "proposal" is not a kind of its own (D7): it is an agent run plus `proposal_ref`, and the human
 * verdict on it is a decision record or an approval.
 */
enum ActionType: string
{
    case AgentRun = 'agent_run';
    case ToolCall = 'tool_call';
    case AiRun = 'ai_run';
    case Approval = 'approval';
    case DecisionRecord = 'decision_record';
    case ContentPublication = 'content_publication';
    case WorkflowInstance = 'workflow_instance';
    case ConversationMessage = 'conversation_message';

    /**
     * A follow-up a person completed (docs/123, docs/107 D7 "one more action type, one column"). Only a
     * `done` follow-up may be credited, and only by a person's assertion in v1 (ruling Q3, C7): no rule
     * or model links a contact to a later result automatically.
     */
    case FollowUp = 'follow_up';

    /** The column on `outcome_attributions` that names an action of this kind. */
    public function column(): string
    {
        return $this->value.'_id';
    }

    /** The table the action lives in. */
    public function table(): string
    {
        return match ($this) {
            self::AgentRun => 'agent_runs',
            self::ToolCall => 'tool_calls',
            self::AiRun => 'ai_runs',
            self::Approval => 'approvals',
            self::DecisionRecord => 'decision_records',
            self::ContentPublication => 'content_publications',
            self::WorkflowInstance => 'workflow_instances',
            self::ConversationMessage => 'conversation_messages',
            self::FollowUp => 'follow_ups',
        };
    }

    /** @return list<string> every action column, in declaration order */
    public static function columns(): array
    {
        return array_map(fn (self $t) => $t->column(), self::cases());
    }
}
