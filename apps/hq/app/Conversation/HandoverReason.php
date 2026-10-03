<?php

namespace App\Conversation;

/**
 * Why a thread needs a person, as a code (docs/95 §5 "Hand-over", stream W2a).
 *
 * The state machine records the reason as a sentence, because a sentence is what a reviewer reads in
 * the audit log and what the simulation scenarios assert on. A notification and the inbox page need
 * the same reason as a fixed vocabulary, so a person skimming Telegram sees `money_or_complaint` and
 * not a paragraph, and so the Bangla dictionary can translate it. The six codes are the six ways a
 * thread reaches `human_required` today; a sentence none of them matches maps to null and is shown
 * as "not recorded" rather than guessed.
 *
 * ⚠ No customer text ever travels with a code: the code is derived from the core's own reason, which
 * is HQ's sentence and never the customer's words.
 */
enum HandoverReason: string
{
    /** The customer asked for a person (an escalation trigger, before the agent). */
    case AsksForPerson = 'asks_for_person';

    /** Money, a refund, a complaint or a damaged item (an escalation trigger, before the agent). */
    case MoneyOrComplaint = 'money_or_complaint';

    /** The customer wrote again while the agent was waiting on them. */
    case SecondInboundWhileWaiting = 'second_inbound_while_waiting';

    /** The support agent is paused, so every thread is a person's. */
    case AgentPaused = 'agent_paused';

    /** The agent is switched off for this thread by a person. */
    case AiDisabled = 'ai_disabled';

    /** The agent was woken and could not serve: a silent hand-over, a refusal, a failure, no reply proposed. */
    case AgentCannotServe = 'agent_cannot_serve';

    /** The prefix the wake-up puts on its own hand-over reason; the rest names what happened. */
    public const CANNOT_SERVE_PREFIX = 'the agent could not serve this thread';

    /** The code for a reason sentence the core or the inbox recorded, or null when none matches. */
    public static function fromText(?string $reason): ?self
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }

        return match (true) {
            $reason === EscalationTriggers::REASON_ASKS_FOR_PERSON => self::AsksForPerson,
            $reason === EscalationTriggers::REASON_MONEY_OR_COMPLAINT => self::MoneyOrComplaint,
            $reason === EscalationTriggers::REASON_SECOND_INBOUND_WHILE_WAITING => self::SecondInboundWhileWaiting,
            $reason === EscalationTriggers::REASON_AGENT_PAUSED => self::AgentPaused,
            $reason === EscalationTriggers::REASON_AI_DISABLED,
            $reason === 'the agent was switched off for this thread' => self::AiDisabled,
            str_starts_with($reason, self::CANNOT_SERVE_PREFIX) => self::AgentCannotServe,
            default => null,
        };
    }

    /** The English label the inbox shows; the Bangla dictionary keys on it. */
    public function label(): string
    {
        return match ($this) {
            self::AsksForPerson => 'The customer asked for a person',
            self::MoneyOrComplaint => 'Money, a complaint or a damaged item',
            self::SecondInboundWhileWaiting => 'The customer wrote again while the agent was waiting',
            self::AgentPaused => 'The support agent is paused',
            self::AiDisabled => 'The agent is switched off for this thread',
            self::AgentCannotServe => 'The agent could not serve this thread',
        };
    }
}
