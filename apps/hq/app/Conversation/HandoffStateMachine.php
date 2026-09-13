<?php

namespace App\Conversation;

use App\Models\Conversation;
use App\Support\Audit\Auditor;
use App\Support\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Moves a thread between the states of `ConversationState`, and nowhere else (docs/14, docs/72 §B).
 *
 * ⛔ Every transition is checked against the table in the enum, written in one transaction, audited,
 * and emitted as `conversation.state_changed`. There is no path that sets `state` directly: a state
 * a reviewer cannot trace to a transition is a state nobody decided.
 *
 * The older `owner` and `handoff_state` columns are kept in step so the Phase 2.5 readers of them
 * stay right; the new column is the truth.
 */
final readonly class HandoffStateMachine
{
    public function __construct(
        private Auditor $auditor,
        private EventRecorder $events,
    ) {}

    /** The state a NEW thread opens in: the agent's only when it is active and allowed on this thread. */
    public function openingState(bool $agentActive, bool $aiDisabled): ConversationState
    {
        return $agentActive && ! $aiDisabled ? ConversationState::AiActive : ConversationState::HumanRequired;
    }

    public function transition(
        Conversation $conversation,
        ConversationState $to,
        ?int $byUserId = null,
        ?string $reason = null,
        ?string $agentKey = null,
    ): Conversation {
        $from = $conversation->state;

        if (! $from->canMoveTo($to)) {
            throw new InvalidTransition(
                "Conversation [{$conversation->uuid}] is [{$from->value}] and cannot move to [{$to->value}]. "
                .'From here it may only move to: '.(implode(', ', array_map(fn (ConversationState $s) => $s->value, $from->allowedNext())) ?: 'nowhere, it is closed').'.'
            );
        }

        return DB::transaction(function () use ($conversation, $from, $to, $byUserId, $reason, $agentKey) {
            $attributes = [
                'state' => $to->value,
                'owner' => $to->owner(),
                'handoff_state' => match ($to) {
                    ConversationState::HumanRequired => 'requested',
                    ConversationState::HumanActive, ConversationState::HumanResolved => 'accepted',
                    ConversationState::AiResumed, ConversationState::AiActive, ConversationState::AiWaiting => $from->owner() === 'human' ? 'returned' : $conversation->handoff_state,
                    ConversationState::Closed => $conversation->handoff_state,
                },
            ];

            if ($to === ConversationState::HumanActive) {
                $attributes['assigned_user_id'] = $byUserId ?? $conversation->assigned_user_id;
            }
            if ($to === ConversationState::AiActive || $to === ConversationState::AiResumed) {
                $attributes['assigned_agent_key'] = $agentKey ?? $conversation->assigned_agent_key;
            }
            if ($to === ConversationState::Closed) {
                $attributes['status'] = 'closed';
                $attributes['closed_at'] = now();
            } elseif ($to === ConversationState::AiWaiting) {
                $attributes['status'] = 'waiting';
            } else {
                $attributes['status'] = 'open';
            }

            $conversation->forceFill($attributes)->save();

            $after = ['from' => $from->value, 'to' => $to->value, 'business_id' => $conversation->business_id];
            if ($byUserId !== null) {
                $this->auditor->record('conversation.state_changed', $conversation, ['state' => $from->value], $after, $reason, $byUserId);
            } else {
                $this->auditor->recordSystem('conversation.state_changed', $conversation, ['state' => $from->value], $after, $reason);
            }

            $this->events->record(
                type: 'conversation.state_changed',
                payload: $after + ['conversation_uuid' => $conversation->uuid, 'reason' => $reason, 'by_user_id' => $byUserId, 'agent_key' => $agentKey],
                source: 'hq:conversation',
                direction: 'outbound',
                aggregateType: 'conversation',
                aggregateId: $conversation->uuid,
                businessId: $conversation->business_id,
            );

            return $conversation;
        });
    }
}
