<?php

namespace App\Conversation;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ShadowComparison;
use App\Support\Audit\Auditor;

/**
 * Pairing what the support agent proposed with what actually went out (docs/72 §D gate P3-G3,
 * docs/78 B.1): the HQ side, built now and inert until the channel shadow begins.
 *
 * The rule is small and deterministic. When an external outbound lands on a thread (the n8n bot's
 * reply, as Meta echoed it), the most recent support-agent proposal on that thread that answered the
 * same customer message (made after the last inbound before the echo) and is not yet paired becomes
 * one `shadow_comparisons` row: the proposal's digest, the echo's digest, the run and the message. The
 * verdict (`same`, `better`, `worse`, `different`) is a person's, on the thread page, and audited;
 * a proposal nothing was sent for within a day is recorded `unmeasured` by the daily sweep.
 *
 * ⛔ Digests and references, never a second copy of the texts: the texts are on the run and the
 * message already, and a comparison table that copied them would be a third place personal data lives.
 */
final class ShadowComparisons
{
    public const VERDICTS = ['same', 'better', 'worse', 'different'];

    public const UNMEASURED_AFTER_HOURS = 24;

    public function __construct(private readonly Auditor $auditor) {}

    /** The external outbound arrived: pair it with the proposal that answered the same message, if there is one. */
    public function pair(ConversationMessage $external): ?ShadowComparison
    {
        if ($external->direction !== 'outbound' || $external->author_type !== 'external') {
            return null;
        }

        $conversationUuid = $external->conversation()->value('uuid');
        $lastInbound = ConversationMessage::query()
            ->where('conversation_id', $external->conversation_id)
            ->where('direction', 'inbound')
            ->where('id', '<', $external->id)
            ->latest('id')
            ->first();

        $run = AgentRun::query()
            ->where('agent_key', ConversationService::SUPPORT_AGENT)
            // The thread the runtime bound the run to (docs/90 A.1 step 3), or the correlation id for rows written before the binding existed.
            ->where(fn ($q) => $q->where('conversation_uuid', $conversationUuid)->orWhere('correlation_id', $conversationUuid))
            ->when($lastInbound !== null, fn ($q) => $q->where('created_at', '>=', $lastInbound->created_at))
            ->where('created_at', '<=', $external->created_at)
            ->whereNotIn('id', ShadowComparison::query()->where('conversation_id', $external->conversation_id)->whereNotNull('agent_run_id')->select('agent_run_id'))
            ->latest('id')
            ->get()
            ->first(fn (AgentRun $r) => self::proposalText($r) !== null);

        if ($run === null) {
            return null;
        }

        return ShadowComparison::create([
            'conversation_id' => $external->conversation_id,
            'agent_run_id' => $run->id,
            'hq_message_id' => null,
            'external_message_ref' => $external->external_message_ref,
            'external_message_id' => $external->id,
            'hq_digest' => hash('sha256', (string) self::proposalText($run)),
            'external_digest' => hash('sha256', (string) $external->body),
            'compared_at' => now(),
        ]);
    }

    /** A person's verdict on a pairing, audited. */
    public function verdict(ShadowComparison $comparison, string $verdict, int $byUserId): ShadowComparison
    {
        if (! in_array($verdict, self::VERDICTS, true)) {
            throw new \InvalidArgumentException('A verdict is one of '.implode(', ', self::VERDICTS).", not [{$verdict}].");
        }

        $before = $comparison->verdict;
        $comparison->forceFill(['verdict' => $verdict, 'verdict_by' => $byUserId, 'verdict_at' => now()])->save();
        $this->auditor->record('shadow_comparison.verdict', $comparison, ['verdict' => $before], ['verdict' => $verdict], null, $byUserId);

        return $comparison;
    }

    /**
     * Proposals older than a day that nothing was sent for become `unmeasured` rows, so the week's
     * count is honest about silence. Returns how many were written.
     */
    public function sweepUnmeasured(): int
    {
        $written = 0;
        $runs = AgentRun::query()
            ->where('agent_key', ConversationService::SUPPORT_AGENT)
            ->where('created_at', '<', now()->subHours(self::UNMEASURED_AFTER_HOURS))
            ->whereNotIn('id', ShadowComparison::query()->whereNotNull('agent_run_id')->select('agent_run_id'))
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $text = self::proposalText($run);
            if ($text === null) {
                continue;
            }
            $conversationId = Conversation::query()->where('uuid', $run->conversation_uuid ?? $run->correlation_id)->value('id');
            if ($conversationId === null) {
                continue;
            }
            ShadowComparison::create([
                'conversation_id' => $conversationId,
                'agent_run_id' => $run->id,
                'hq_digest' => hash('sha256', $text),
                'compared_at' => now(),
                'verdict' => 'unmeasured',
            ]);
            $written++;
        }

        return $written;
    }

    /** The one reply the run proposed, or null. */
    public static function proposalText(AgentRun $run): ?string
    {
        foreach ((array) $run->proposals as $p) {
            if (is_array($p) && ($p['kind'] ?? null) === 'proposal' && ($p['tool'] ?? null) === 'messaging.send_reply') {
                $text = data_get($p, 'args.text');

                return is_string($text) ? $text : null;
            }
        }

        return null;
    }
}
