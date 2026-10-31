<?php

namespace App\OwnerConsole\Replies;

use App\Conversation\ConversationService;
use App\Conversation\OutboundPolicy;
use App\Decisions\DecisionVocabulary;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use App\Models\ReplyOptionSet;
use App\Models\User;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Support\Str;

/**
 * Support's reply options (docs/143 E, docs/138 ruling 11): up to THREE drafts for one thread, labelled A, B
 * and C. A person chooses one, may edit it, and that exact final text is the only thing that can be sent.
 *
 *  - Proposing writes drafts. It sends nothing and calls no tool. The outbound policy (consent, handover
 *    state, the one-reply-per-inbound rule) is asked at proposal time too, so a thread that may not be
 *    answered gets no options.
 *  - Choosing is the person's act and the send is the existing `messaging.send_reply` gateway call as that
 *    person (`hq.inbox.manage`), exactly the call the inbox's proposal decision makes: consent, script and
 *    outbound policy are the gateway's. A set is single use (one conditional update open -> chosen), so two
 *    clicks send once; the other labels can never be sent; a failed send puts the set back to open.
 *  - Every pick is a decision record with the label chosen and whether the person edited it, and an audit row.
 *
 * Inert: flag `support.reply_options` OFF. The Support agent's prompts and evaluation corpora are untouched:
 * options are read from the proposals a Support run already records.
 */
final class ReplyOptionService
{
    public const MAX_OPTIONS = 3;

    public const MAX_TEXT = 2000;

    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly ToolGateway $gateway,
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /** @param  list<string>  $texts */
    public function propose(Conversation $conversation, array $texts, ?AgentRun $run = null): ReplyOptionSet
    {
        $this->assertOn();
        $texts = array_values(array_filter(array_map(fn ($t) => trim((string) $t), $texts), fn ($t) => $t !== ''));
        if ($texts === [] || count($texts) > self::MAX_OPTIONS) {
            throw new Refused('Offer between 1 and '.self::MAX_OPTIONS.' reply options.');
        }
        foreach ($texts as $text) {
            if (mb_strlen($text) > self::MAX_TEXT) {
                throw new Refused('A reply option is at most '.self::MAX_TEXT.' characters.');
            }
        }
        if ($conversation->status === 'closed') {
            throw new Refused('The conversation is closed.');
        }
        if (($why = $this->policy->refusal($conversation, humanAuthor: false)) !== null) {
            throw new Refused('The outbound policy gives this thread no reply: '.$why);
        }
        if ($run !== null && ($run->agent_key !== ConversationService::SUPPORT_AGENT || (int) $run->business_id !== (int) $conversation->business_id)) {
            throw new Refused('That run is not a Support run on this thread\'s business.');
        }

        $options = [];
        foreach ($texts as $i => $text) {
            $options[] = ['label' => chr(65 + $i), 'text' => $text];
        }
        $set = ReplyOptionSet::query()->create([
            'uuid' => (string) Str::uuid(), 'business_id' => $conversation->business_id, 'conversation_id' => $conversation->id,
            'agent_run_id' => $run?->id, 'options' => $options, 'state' => 'open',
        ]);
        $this->auditor->recordAgent(ConversationService::SUPPORT_AGENT, 'reply_options.proposed', $set,
            after: ['set' => $set->uuid, 'conversation' => $conversation->uuid, 'business_id' => $conversation->business_id, 'count' => count($options)]);

        return $set;
    }

    /** The options a Support run already recorded as `messaging.send_reply` proposals, as one set (at most three). */
    public function fromRun(AgentRun $run, Conversation $conversation): ReplyOptionSet
    {
        $texts = collect((array) $run->proposals)
            ->filter(fn ($p) => is_array($p) && ($p['kind'] ?? null) === 'proposal' && ($p['tool'] ?? null) === 'messaging.send_reply')
            ->map(fn ($p) => (string) data_get($p, 'args.text', ''))->take(self::MAX_OPTIONS)->values()->all();

        return $this->propose($conversation, $texts, $run);
    }

    /** The person picks a label, optionally edits it, and that exact text is sent through the gateway as that person. */
    public function choose(ReplyOptionSet $set, string $label, ?string $editedText, User $by): ReplyOptionSet
    {
        $this->assertOn();
        $this->assertMayAct($set, $by);

        $option = collect((array) $set->options)->firstWhere('label', $label);
        if ($option === null) {
            throw new Refused('There is no option '.$label.' in this set.');
        }
        $final = trim($editedText ?? (string) $option['text']);
        if ($final === '' || mb_strlen($final) > self::MAX_TEXT) {
            throw new Refused('The reply must be 1 to '.self::MAX_TEXT.' characters.');
        }
        $conversation = Conversation::query()->whereKey($set->conversation_id)->where('business_id', $set->business_id)->first();
        if ($conversation === null) {
            throw new Refused('The thread is not in this business.');
        }

        // Single use: exactly one caller moves the set out of `open`.
        $claimed = ReplyOptionSet::query()->whereKey($set->getKey())->where('state', 'open')->update([
            'state' => 'chosen', 'chosen_label' => $label, 'final_text' => $final, 'decided_by_user_id' => $by->getKey(), 'decided_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed === 0) {
            throw new Refused('This set was already decided.');
        }

        $outcome = $this->gateway->execute(ToolRequest::forOperator($by, 'messaging.send_reply',
            ['business_slug' => $conversation->business?->slug, 'conversation_uuid' => $conversation->uuid, 'text' => $final], $conversation->business_id));

        $edited = $final !== (string) $option['text'];
        DecisionRecord::query()->create([
            'agent_key' => ConversationService::SUPPORT_AGENT, 'user_id' => $by->getKey(), 'business_id' => $conversation->business_id,
            'context_type' => 'conversation', 'context_id' => $conversation->uuid,
            'proposal' => ['reply_option_set' => $set->uuid, 'options' => array_column((array) $set->options, 'label')],
            'final' => ['decision' => 'send', 'label' => $label, 'text' => $final, 'edited' => $edited],
            'delta' => $edited ? ['from' => (string) $option['text'], 'to' => $final] : null,
            'decision' => DecisionVocabulary::fromInboxAction('send', $edited),
            'reason' => null,
            'outcome' => ['status' => $outcome->status->value, 'error' => $outcome->error ?? $outcome->policyReason],
            'correlation_id' => $set->agentRun?->correlation_id ?? (string) Str::uuid(),
        ]);

        if ($outcome->ok()) {
            $set->forceFill(['send_status' => $outcome->status->value])->save();
        } else {
            // A decision that could not be carried out is not a decision: the set is open again.
            ReplyOptionSet::query()->whereKey($set->getKey())->update(['state' => 'open', 'chosen_label' => null, 'final_text' => null,
                'decided_by_user_id' => null, 'decided_at' => null, 'send_status' => $outcome->status->value, 'updated_at' => now()]);
        }
        $this->auditor->record('reply_options.chosen', $set, after: ['set' => $set->uuid, 'label' => $label, 'edited' => $edited,
            'business_id' => $set->business_id, 'outcome' => $outcome->status->value], actorUserId: $by->getKey());

        return $set->refresh();
    }

    public function discard(ReplyOptionSet $set, User $by): ReplyOptionSet
    {
        $this->assertOn();
        $this->assertMayAct($set, $by);
        $moved = ReplyOptionSet::query()->whereKey($set->getKey())->where('state', 'open')
            ->update(['state' => 'discarded', 'decided_by_user_id' => $by->getKey(), 'decided_at' => now(), 'updated_at' => now()]);
        if ($moved === 0) {
            throw new Refused('This set was already decided.');
        }
        $this->auditor->record('reply_options.discarded', $set, after: ['set' => $set->uuid, 'business_id' => $set->business_id], actorUserId: $by->getKey());

        return $set->refresh();
    }

    private function assertOn(): void
    {
        if (! Flag::on(Flag::REPLY_OPTIONS)) {
            throw new Refused('Reply options are switched off.');
        }
    }

    private function assertMayAct(ReplyOptionSet $set, User $by): void
    {
        if (! $by->is_active || ! $by->can('hq.inbox.manage')) {
            throw new Refused('You do not hold hq.inbox.manage.');
        }
        if (! $this->scope->allows($by, $set->business_id) || $this->scope->all($by) !== null && ! in_array($set->business_id, $this->scope->all($by), true)) {
            throw new Refused('You may not act for that business.');
        }
    }
}
