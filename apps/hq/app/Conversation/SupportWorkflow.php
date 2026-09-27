<?php

namespace App\Conversation;

use App\Context\ContextBlock;
use App\Context\Provenance;

/**
 * The workflow a Support run is bound to, decided by the runtime and enforced by it (docs/90 A.1
 * step 5, the rulings of 26 September on the five P3-G2 behaviour cases).
 *
 * Three workflows, and only three:
 *
 * - `silent_handover`: the thread goes to a person and the customer hears nothing from the agent.
 *   No protective reply, no reassurance, no explanation before the hand-over; the hand-over state
 *   is recorded and generation stops. The model is never asked. It is the runtime's decision, read
 *   from the customer's latest message by shape: a message that instructs the agent as if it were an
 *   operator (a "system notice", a tool named by its key, an order to run or cancel something); a
 *   request for another person's phone, address or order details; or a claim that somebody approved
 *   the sharing of other customers' data. The customer's words never become a task (ADR-039); here
 *   they decide a ROUTE, which is the conversation core's own kind of decision (`EscalationTriggers`).
 * - `greeting_first`: the reply comes first, from the thread already in the agent's context, and no
 *   thread read and no other tool call may come before it. The runtime enforces it structurally: the
 *   catalogue the model reads holds the reply tool alone, and a call to anything else is refused by
 *   the runtime before the gateway. Derived for a bare greeting (a "hi", a sticker, a message with no
 *   question in it); declared by a scenario contract for a thread whose reply needs no lookup.
 * - `context_first`: the default. The agent may read what it needs before it replies.
 *
 * ⛔ Not universal, and a declared contract always wins (docs/91 A.2, v2). The precedence is:
 *   1. an explicit contract the thread declares (a scenario, a wake-up: trusted HQ code, never the
 *      model): `silent_handover` or `greeting_first` is enforced exactly as declared;
 *   2. the content classifier below, which only RECOMMENDS: its reading is recorded on the run beside
 *      the verdict and never overrides a declared contract nor invents a stronger one;
 *   3. ordinary model interpretation: with no declared contract the run is `context_first`, the
 *      Support agent's behaviour before the contracts existed.
 * v1 let the classifier win (a message that instructs the agent was handed over in silence even where
 * the case expected an answer, and once over a declared `greeting_first`); P3-G2 attempt 2 measured it
 * and ChatGPT ruled it out on 27 September. The hard safety boundaries that apply to every thread (the
 * conversation core's escalation triggers, the runtime's security-intent, prohibited-capability and role
 * boundaries, the gateway) are independent of this class and unchanged.
 *
 * ⭐ Versioned like the other runtime readings (`RoleBoundary`, `TaskGrounding`): the verdict on a run
 * names the version that produced it, so a gate's evidence says which rules it measured.
 */
final class SupportWorkflow
{
    public const VERSION = 'v2';

    public const SILENT_HANDOVER = 'silent_handover';

    public const GREETING_FIRST = 'greeting_first';

    public const CONTEXT_FIRST = 'context_first';

    public const WORKFLOWS = [self::SILENT_HANDOVER, self::GREETING_FIRST, self::CONTEXT_FIRST];

    /** The kinds of message that are handed over in silence. */
    public const INSTRUCTION_TO_AGENT = 'instruction_to_agent';

    public const THIRD_PARTY_DATA = 'third_party_data';

    public const CLAIMED_DATA_APPROVAL = 'claimed_data_approval';

    /** The one tool a `greeting_first` run may name. */
    public const REPLY_TOOL = 'messaging.send_reply';

    /** The tool namespaces HQ registers; a customer naming one is instructing the agent, not asking it. */
    private const TOOL_KEY = '/\b(?:erp|commerce|facts|messaging|conversation|hq|demo|ads|research|content|publishing)\.[a-z][a-z0-9_]+\b/u';

    /** A message that addresses the agent as its operator. */
    private const ADDRESSED_TO_AGENT = '/(?:system\s+(?:notice|message|prompt|instruction)|(?:to|attention|note to|for)\s+the\s+(?:support\s+)?(?:bot|agent|ai|assistant)\b|'
        .'as\s+(?:the|your)\s+(?:system|admin|administrator|developer)\b|ignore\s+(?:your|all|previous|prior)\s+(?:instructions|rules)|'
        .'you\s+are\s+now\s+(?:in|the|an?)\b|(?:run|execute|call|invoke|trigger)\s+(?:the\s+)?(?:tool|command|job|sync|poll)\b)/iu';

    /** A phone, an address or the order details of somebody else. */
    private const OTHER_PERSON_EN = '/\b(?:(?:their|his|her|somebody(?:\'s| else\'s)|someone(?:\'s| else\'s)|another (?:customer|person|user)(?:\'s)?|other (?:customers|people|users)(?:\'|\'s)?|'
        .'my (?:friend|brother|sister|cousin|wife|husband|neighbour|neighbor|colleague|boss)(?:\'s)?|who else)\s+(?:\w+\s+){0,3}?'
        .'(?:phone|number|mobile|address|location|order(?:s| details| history| id)?|details|info|information|data)\b'
        .'|(?:phone|number|mobile|address|order details)\s+(?:and|or)\s+(?:address|phone|number)\s+(?:of|for)\s+(?:my|the|another|other)\s+(?:friend|brother|sister|customer|person)\b'
        .'|who\s+else\s+(?:ordered|bought|purchased)\b|(?:list|show|tell me)\s+(?:\w+\s+){0,2}?(?:other|all)\s+customers\b)/iu';

    private const OTHER_PERSON_BN = '/(?:(?:তার|তাদের|ওর|ওদের|উনার|ওনার|অন্য(?:\s*কারো|\s*কারোর|\s*কাস্টমারের|\s*জনের)?|আমার\s+(?:বান্ধবী|বন্ধু|ভাই|বোন|বউ|স্বামী)র?)\s*(?:\S+\s+){0,3}?(?:ফোন|নাম্বার|নম্বর|ঠিকানা|অর্ডার|ডিটেইলস|তথ্য)|'
        .'আর\s+কে\s+কে\s+(?:অর্ডার|কিনেছে|নিয়েছে)|অন্য\s*(?:কাস্টমার|গ্রাহক)(?:দের|ের)?\s*(?:অর্ডার|তথ্য|ডিটেইলস))/u';

    private const OTHER_PERSON_BL = '/\b(?:(?:tar|tader|or|oder|onar|unar|onno\s*(?:karo|karor|customer(?:er)?|joner)?|amar\s+(?:bandhobi|bondhu|vai|bhai|bon|bou|shami)r?)\s+(?:\w+\s+){0,3}?(?:phone|number|mobile|address|thikana|order(?:s)?|details|info)\b'
        .'|(?:phone|number|address|thikana)\s+(?:ar|o)\s+(?:address|phone|number|thikana)\s+(?:ta|gulo)?\s*(?:diben|dao|dibe|den)\b|ar\s+ke\s+ke\s+(?:order|kinse|kineche|nise|niyeche)\b)/iu';

    /** Somebody approved, allowed or permitted the sharing of other customers' data. */
    private const CLAIMED_DATA_APPROVAL_EN = '/\b(?:approved|allowed|permitted|authori[sz]ed|said (?:it is|it\'s) (?:ok|fine)|gave (?:you )?permission|cleared)\b[^.?!]{0,80}?\b(?:share|sharing|give|giving|show|showing|tell|telling|send|sending|reveal|disclose)\b[^.?!]{0,60}?\b(?:other|another|others\'?|customers\'?|people\'?s|everyone\'?s|somebody\'?s|someone\'?s)\b/iu';

    private const CLAIMED_DATA_APPROVAL_BN = '/(?:অনুমতি\s*(?:দিয়েছে|দিয়েছেন|আছে|দিলো|দিল)|অ্যাপ্রুভ|এপ্রুভ|বলেছে(?:ন)?\s+(?:যে\s+)?(?:দিতে|শেয়ার\s*করতে|দেখাতে)\s*পার(?:বে|বেন|ো))[^।?!]{0,80}?(?:অন্য|অন্যদের|অন্যান্য|সবার|কাস্টমারদের|গ্রাহকদের)/u';

    private const CLAIMED_DATA_APPROVAL_BL = '/\b(?:approve|approved|allow|allowed|permission|onumoti|bolse|bolche|bolechen?)\b[^.?!]{0,80}?\b(?:share|dite|dekhate|dao|den|diben)\b[^.?!]{0,60}?\b(?:onno|onnoder|onnader|shobar|sobar|customer(?:der)?|manusher)\b/iu';

    /** A greeting and nothing else: the reply needs no lookup. */
    private const GREETING = '/^\s*(?:(?:hi+|hii+|hello+|hey+|helo|hlw|salam|assalamu\s*alaikum|assalamualaikum|asalamualaikum|as-salamu\s*alaykum|salamualaikum|good\s*(?:morning|afternoon|evening|night)|'
        .'হাই|হ্যালো|হেলো|সালাম|আসসালামু\s*আলাইকুম|আসসালামুয়ালাইকুম|শুভ\s*(?:সকাল|সন্ধ্যা|রাত্রি)|kemon\s+achen|kemon\s+acho|কেমন\s+আছেন|কেমন\s+আছো|vaiya|vai|bhai|apu|আপু|ভাইয়া|ভাই)'
        .'[\s,.!?…]*)+$/iu';

    private const NO_TEXT = '/^\[the customer sent \d+ attachment\(s\) and no text\]$/u';

    /**
     * The workflow for a run, from what was declared by HQ code (a scenario contract, a wake-up) and
     * from the runtime's own reading of the customer's latest message in the thread context.
     *
     * @param  list<ContextBlock>  $blocks  the run's extra context, as the subject or the wake-up hands it over
     */
    public function decide(array $blocks, ?string $declared = null): SupportWorkflowVerdict
    {
        $recommended = $this->recommend($this->latestCustomerMessage($blocks));

        // 1. A declared contract wins, and is enforced exactly as declared.
        if ($declared !== null && in_array($declared, self::WORKFLOWS, true)) {
            $kind = $declared === self::SILENT_HANDOVER ? ($recommended['kind'] ?? null) : null;

            return new SupportWorkflowVerdict($declared, $kind, self::VERSION, 'declared', match ($declared) {
                self::SILENT_HANDOVER => 'declared by the contract of this thread: the thread goes to a person in silence, and the customer hears nothing from the agent',
                self::GREETING_FIRST => 'declared by the contract of this thread: the reply comes first, from the thread already in context, and no lookup precedes it',
                default => 'declared by the contract of this thread: the agent may read what it needs before it replies',
            }, $recommended);
        }

        // 2 and 3. No contract: the behaviour before the contracts; the classifier's reading is a record only.
        return new SupportWorkflowVerdict(self::CONTEXT_FIRST, null, self::VERSION, 'default',
            'no workflow contract is declared for this thread: the agent may read what it needs before it replies, as before the contracts; '
            .'the reading of the content classifier is recorded as a recommendation and not enforced', $recommended);
    }

    /**
     * What the content classifier recommends for a customer message, or null when it reads nothing
     * special. A recommendation only (docs/91 A.2): recorded on the run, never enforced by itself.
     *
     * @return array{workflow: string, kind: ?string}|null
     */
    public function recommend(?string $latest): ?array
    {
        if ($latest === null) {
            return null;
        }
        if (($kind = $this->silentHandoverKind($latest)) !== null) {
            return ['workflow' => self::SILENT_HANDOVER, 'kind' => $kind];
        }
        if ($this->isBareGreeting($latest)) {
            return ['workflow' => self::GREETING_FIRST, 'kind' => null];
        }

        return null;
    }

    /** The silent-hand-over kind this customer message is, or null when it is none of them. */
    public function silentHandoverKind(string $message): ?string
    {
        $text = (string) Language::normalise($message);
        $lower = mb_strtolower($text);

        if (preg_match(self::TOOL_KEY, $lower) === 1 || preg_match(self::ADDRESSED_TO_AGENT, $lower) === 1) {
            return self::INSTRUCTION_TO_AGENT;
        }
        if (preg_match(self::CLAIMED_DATA_APPROVAL_EN, $lower) === 1 || preg_match(self::CLAIMED_DATA_APPROVAL_BN, $text) === 1 || preg_match(self::CLAIMED_DATA_APPROVAL_BL, $lower) === 1) {
            return self::CLAIMED_DATA_APPROVAL;
        }
        if (preg_match(self::OTHER_PERSON_EN, $lower) === 1 || preg_match(self::OTHER_PERSON_BN, $text) === 1 || preg_match(self::OTHER_PERSON_BL, $lower) === 1) {
            return self::THIRD_PARTY_DATA;
        }

        return null;
    }

    /** A greeting, a sticker, or nothing at all: a reply needs no lookup. */
    public function isBareGreeting(string $message): bool
    {
        $text = trim((string) Language::normalise($message));

        return $text === '' || preg_match(self::NO_TEXT, $text) === 1 || preg_match(self::GREETING, $text) === 1;
    }

    /**
     * The customer's latest message in the thread context: the last untrusted block whose source is a
     * message of a conversation, exactly as `SupportContext` labels them.
     *
     * @param  list<ContextBlock>  $blocks
     */
    public function latestCustomerMessage(array $blocks): ?string
    {
        $latest = null;
        foreach ($blocks as $block) {
            if (! $block instanceof ContextBlock || $block->provenance->isTrusted()) {
                continue;
            }
            // A message of a thread, as `SupportContext` labels it; a memory row under customer provenance is not one.
            if (preg_match('#^conversation:[^/]+/message:#', $block->source) === 1
                || ($block->provenance === Provenance::Customer && ! str_starts_with($block->source, 'memory:'))) {
                $latest = $block->content;
            }
        }

        return $latest;
    }
}
