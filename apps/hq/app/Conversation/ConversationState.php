<?php

namespace App\Conversation;

/**
 * Where a thread is between the agent and a person (docs/14, docs/72 §B).
 *
 * The states the n8n bot already implements in a simpler form, kept so operators are not retrained:
 * the agent is answering, the agent is waiting on the customer, a person is needed, a person is
 * answering, a person has resolved it, the agent has it back, or it is closed. `human_required` is
 * where every thread lands while the support agent is paused, which is all of this milestone: the
 * inbox works before the AI does.
 */
enum ConversationState: string
{
    case AiActive = 'ai_active';
    case AiWaiting = 'ai_waiting';
    case HumanRequired = 'human_required';
    case HumanActive = 'human_active';
    case HumanResolved = 'human_resolved';
    case AiResumed = 'ai_resumed';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AiActive => [self::AiWaiting, self::HumanRequired, self::Closed],
            self::AiWaiting => [self::AiActive, self::HumanRequired, self::Closed],
            self::HumanRequired => [self::HumanActive, self::Closed],
            self::HumanActive => [self::HumanResolved, self::Closed],
            self::HumanResolved => [self::AiResumed, self::Closed],
            self::AiResumed => [self::AiActive, self::HumanRequired, self::Closed],
            self::Closed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** Who is answering in this state, in the older `owner` vocabulary the table also carries. */
    public function owner(): string
    {
        return match ($this) {
            self::AiActive, self::AiWaiting, self::AiResumed => 'ai',
            self::HumanRequired, self::HumanActive, self::HumanResolved => 'human',
            self::Closed => 'none',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AiActive => 'agent answering',
            self::AiWaiting => 'waiting on the customer',
            self::HumanRequired => 'needs a person',
            self::HumanActive => 'a person is answering',
            self::HumanResolved => 'resolved by a person',
            self::AiResumed => 'handed back to the agent',
            self::Closed => 'closed',
        };
    }
}
