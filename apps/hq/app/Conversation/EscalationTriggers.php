<?php

namespace App\Conversation;

use App\Models\Conversation;

/**
 * When a thread must go to a person, decided in code and not by the model (docs/14, docs/72 §B).
 *
 * ⛔ Deterministic on purpose. A model asked "does this customer need a human?" gives a probability;
 * an operator who was promised that angry customers reach them needs a rule. The vocabulary is
 * plain and in both scripts, because the customers write in both, and it is a floor: the support
 * agent may still escalate on its own judgement, it may never talk a thread past one of these.
 */
final class EscalationTriggers
{
    /** The customer asks for a person. */
    private const ASKS_FOR_PERSON = [
        'human', 'real person', 'a person', 'someone real', 'talk to someone', 'speak to someone', 'speak with someone',
        'talk to a manager', 'manager', 'call me', 'phone me', 'ring me', 'customer service', 'operator',
        'মানুষ', 'কারো সাথে কথা', 'কারোর সাথে কথা', 'কারও সাথে কথা', 'ম্যানেজার', 'কল করুন', 'কল দিন', 'ফোন করুন', 'ফোন দিন',
        'সরাসরি কথা', 'কথা বলতে চাই', 'একজন মানুষ',
        'manush', 'manager er sathe', 'kotha bolte chai', 'call diben', 'call koren', 'phone koren',
    ];

    /** Money, complaint, damage: the things the agent must never settle on its own. */
    private const MONEY_OR_COMPLAINT = [
        'refund', 'money back', 'chargeback', 'charged twice', 'double charged', 'payment failed', 'paid twice', 'not refunded',
        'complaint', 'complain', 'cheated', 'fraud', 'scam', 'wrong item', 'wrong product', 'damaged', 'broken', 'rotten', 'expired',
        'lawyer', 'consumer rights', 'report you',
        'রিফান্ড', 'টাকা ফেরত', 'টাকা ফিরিয়ে', 'পেমেন্ট', 'দুইবার টাকা', 'অভিযোগ', 'প্রতারণা', 'ঠকিয়েছেন', 'ভুল পণ্য', 'ভুল প্রোডাক্ট',
        'নষ্ট', 'ভাঙা', 'পচা', 'মেয়াদ', 'ভোক্তা অধিকার',
        'taka ferot', 'tk ferot', 'refund chai', 'vul product', 'nosto', 'bhanga', 'pocha', 'ovijog', 'protarona',
    ];

    /**
     * The reason this inbound message must reach a person, or null when the agent may keep it.
     *
     * @param  bool  $agentActive  whether the support agent is active for this business today
     */
    public function reasonFor(Conversation $conversation, ?string $body, bool $agentActive): ?string
    {
        if (! $agentActive) {
            return 'the support agent is not active, so every thread is a person\'s';
        }

        if ($conversation->ai_disabled) {
            return 'the agent is switched off for this thread';
        }

        $text = mb_strtolower(trim((string) $body));

        if ($text !== '') {
            foreach (self::ASKS_FOR_PERSON as $phrase) {
                if (str_contains($text, mb_strtolower($phrase))) {
                    return 'the customer asked for a person';
                }
            }
            foreach (self::MONEY_OR_COMPLAINT as $phrase) {
                if (str_contains($text, mb_strtolower($phrase))) {
                    return 'the message is about money, a complaint or a damaged item';
                }
            }
        }

        if ($conversation->state === ConversationState::AiWaiting) {
            return 'the customer wrote again while the agent was waiting';
        }

        return null;
    }
}
