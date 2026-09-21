<?php

namespace App\Agents;

use RuntimeException;
use Throwable;

/**
 * Detects a REQUESTED outbound action in the trusted operator text, and whether the agent's role
 * forbids it, deterministically, before the model is called (docs/86, the sibling of ADR-042 and
 * ADR-044).
 *
 * ⭐ Gate 11 attempt 2 failed three cases on one pattern (docs/83 C.6). Asked to send a supplier a
 * WhatsApp and to put a post on the LaLaDia page, OpenAI's model wrote "outside the Executive role"
 * into its own task line and answered the standing briefing in its place. Every runtime check was
 * silent: `SecurityIntent` reads authority, `ProhibitedCapability` reads infrastructure, and nothing
 * in the runtime knew that the Executive does not send, publish or answer customers. That rule lived
 * only in the prompt, so it held exactly as long as the model chose to follow it. ChatGPT's line,
 * from the thread: an Executive request to publish must never reach the model as an executable task.
 *
 * So the runtime now reads the operator's own words for three kinds of outbound action:
 *
 *  - `outbound_message`: sending, messaging, texting, emailing, WhatsApp-ing, contacting, telling or
 *    asking a party outside the conversation; a channel verb even addressed to the operator ("email
 *    me"), because no agent holds a channel;
 *  - `publication`: posting, publishing or putting up content on a page, feed, account or site, or
 *    running an ad;
 *  - `customer_reply`: answering, replying or responding to a customer, or drafting that reply.
 *
 * ⛔ Not a keyword list. A clause is flagged only when it ASKS for the action: in English the action
 * verb opens the clause once the request lead-ins are stripped ("please", "then", "can you", "I want
 * you to"); in Bangla and Banglish, whose verb comes last, the clause names the channel or the content
 * and ends in an imperative, and carries no question word. A question ("how many messages went out"),
 * a statement about the past ("the supplier sent us a message yesterday") and a request for
 * information about a channel ("report on WhatsApp activity") never open with the action and are
 * never flagged. The model's task line, its paraphrase, memory and the briefing are never read: only
 * the trusted text.
 *
 * ⛔ Per role. What is forbidden is the agent's role policy (`RolePolicy`, `config/hq.php` `roles`):
 * the Executive may do none of the three, and the Support agent's role IS the customer reply, on the
 * customer's own thread, through its gated path. A kind the role permits is recorded, not acted on.
 *
 * ⛔ Fails closed and is versioned, like its siblings. A detector that cannot say "permitted" says
 * "forbidden", with its error on the row.
 *
 * ⚠ What it cannot do, said plainly: it matches the SHAPE of a request, so a request phrased far
 * from every shape ("I need the supplier to hear from us today") passes it, and the prompt's role
 * rule still asks the model to refuse what the detector misses. The gate measures both.
 */
final class RoleBoundary
{
    public const VERSION = 'v1';

    public const OUTBOUND_MESSAGE = 'outbound_message';

    public const PUBLICATION = 'publication';

    public const CUSTOMER_REPLY = 'customer_reply';

    /** The detector failed; read as forbidden (fail closed). */
    public const DETECTOR_ERROR = 'detector_error';

    public const KINDS = [self::OUTBOUND_MESSAGE, self::PUBLICATION, self::CUSTOMER_REPLY];

    /** Words that open a request without being its action. Stripped, repeatedly, from a clause's start. */
    private const LEAD_IN = '/^(?:please|pls|plz|kindly|then|also|and|now|so|just|quickly|asap|right away|immediately|today|okay|ok|sure|'
        .'go ahead and|go and|could you(?: please)?|can you(?: please)?|would you(?: please)?|will you(?: please)?|'
        .'i want you to|i need you to|i\'?d like you to|i would like you to|you should|you must|you need to|'
        .'make sure (?:to|you)|remember to|don\'?t forget to|be sure to|for me|on my behalf)[\s,:]+/u';

    /** Somebody outside the conversation. Never the operator: "tell me", "send me" and "ask me" are the conversation. */
    private const PARTY = '(?:the |our |this |that |a |an |every |all |each |those |these |my |their |his |her )?'
        .'(?:suppliers?|vendors?|customers?|shoppers?|buyers?|clients?|couriers?|riders?|dealers?|distributors?|wholesalers?|retailers?|'
        .'farmers?|manufacturers?|partners?|team|staff|employees?|workers?|everyone|everybody|people|group|them|him|her|landlord|bank|accountant)';

    private const CUSTOMER = '(?:customers?|shoppers?|buyers?|clients?)';

    /** A channel an agent does not hold. */
    private const CHANNEL = '(?:whats ?app|messages?|msgs?|sms|texts?|e-?mails?|mails?|dms?|voice notes?|voice messages?|reminders?|notifications?|invites?|invitations?|letters?|notes?)';

    /** What follows a channel word when the channel is being ASKED ABOUT, not used. */
    private const ABOUT_A_CHANNEL = '(?:from|about|of|was|is|are|were|that|which|and|history|log|logs|count|counts|volume|activity|address|addresses|id|ids|list|lists|'
        .'thread|threads|inbox|account|accounts|settings|template|templates|campaign|campaigns|report|reports|balance|stats|statistics|open|delivery|rate|rates|'
        .'performance|reach|engagement|likes|views|metrics|insights|numbers|results|counts)';

    /** Content that is published. */
    private const CONTENT = '(?:posts?|photos?|pictures?|pics?|videos?|reels?|stor(?:y|ies)|announcements?|ads?|adverts?|advertisements?|captions?|banners?|tweets?)';

    /** The places content is published to. */
    private const PLACE = '(?:page|feed|facebook|fb|instagram|insta|ig|tiktok|youtube|website|site|timeline|channel|group|social|socials|linkedin|twitter|wall)';

    private const ARTICLE = '(?:(?:a|an|the|our|this|that|some|new|my|one|another|quick|short)\s+)?';

    /** The verbs that open an outbound request, for splitting a clause at a bare "and". */
    private const ACTION_VERB = '(?:send|shoot|forward|whats ?app|sms|text|e-?mail|mail|dm|ping|message|msg|contact|call|phone|ring|notify|inform|remind|alert|'
        .'tell|ask|let|post|publish|repost|put|upload|share|reply|respond|answer|draft|write|compose|boost|run|launch)';

    /**
     * English shapes, matched against a clause whose lead-ins are stripped, so each is anchored at
     * the start: the action is what the clause asks for.
     *
     * @var array<string, list<string>>
     */
    private const ENGLISH = [
        self::CUSTOMER_REPLY => [
            '/^(?:answer|reply(?: back)? to|respond to|write back to|get back to|message back)\s+(?:the |this |that |our |a |an |every |all |each )?'.self::CUSTOMER.'\b/u',
            '/^(?:draft|write|compose|prepare|craft|put together|come up with)\s+'.self::ARTICLE.'(?:[\p{L}\-]+\s+){0,3}?(?:reply|replies|response|responses|answer)\b.*\b'.self::CUSTOMER.'\b/u',
        ],
        self::PUBLICATION => [
            '/^(?:post|publish|republish|repost|tweet)\b(?!\s+'.self::ABOUT_A_CHANNEL.'\b)/u',
            '/^(?:put|put up|put out|upload|schedule|go live with)\s+'.self::ARTICLE.'(?:[\p{L}\-]+\s+){0,3}?'.self::CONTENT.'\b/u',
            '/^(?:put|put up|put out|upload|share|announce|advertise|promote)\b.*\b(?:on|to|onto|in)\s+(?:(?:the|our|a|an|its|their|my)\s+)?(?:[\p{L}\-\.]+\s+){0,3}?'.self::PLACE.'\b/u',
            '/^(?:run|launch|start|boost)\s+'.self::ARTICLE.'(?:[\p{L}\-]+\s+){0,2}?(?:ads?|adverts?|campaigns?|promotions?|promos?|posts?)\b(?!\s+(?:report|reports|numbers|analysis|stats|statistics|performance|results|summary|data)\b)/u',
            '/^(?:draft|write|compose|prepare|craft|come up with)\s+'.self::ARTICLE.'(?:[\p{L}\-]+\s+){0,3}?(?:post|posts|caption|captions|tweet|announcement|ad|advert|newsletter)\b/u',
        ],
        self::OUTBOUND_MESSAGE => [
            // A channel as the verb: "WhatsApp the supplier", "email me the summary", "message them".
            '/^(?:whats ?app|sms|text|e-?mail|mail|dm|ping|message|msg|telegram|imo)\s+(?!'.self::ABOUT_A_CHANNEL.'\b)\S/u',
            // A channel sent: "send the supplier a WhatsApp", "shoot them a quick message", "drop a note to the team".
            '/^(?:send|shoot|fire off|drop|leave)\b.*\b(?:a|an|one|another|quick|short|some|this|that)\s+(?:[\p{L}\-]+\s+)?'.self::CHANNEL.'\b/u',
            // Anything sent to somebody who is not the operator: "send the invoice to the courier", "forward this to Rahim".
            '/^(?:send|shoot|forward|pass on|pass along|deliver)\b.*\bto\s+(?!(?:me|us|myself|ourselves|you)\b)[\p{L}]/u',
            // Somebody sent something directly: "send the supplier the order list".
            '/^(?:send|shoot|forward)\s+'.self::PARTY.'\b/u',
            // Contact, notify, tell or ask a party outside the conversation.
            '/^(?:contact|call|phone|ring|reach out to|get in touch with|follow up with|chase|notify|inform|remind|alert|update|tell|ask|warn|thank|invite)\s+'.self::PARTY.'\b/u',
            '/^let\s+'.self::PARTY.'\s+know\b/u',
            '/^get\s+(?:a |the )?(?:message|word|note)\s+(?:to|out to)\b/u',
            // Drafting a message for somebody outside: "draft a WhatsApp to the supplier".
            '/^(?:draft|write|compose|prepare|craft)\s+'.self::ARTICLE.'(?:[\p{L}\-]+\s+){0,3}?(?:whats ?app|messages?|sms|texts?|e-?mails?|letters?|dms?)\b/u',
        ],
    ];

    /** Bangla: the channel or content named, an imperative at the end, no question word. `\b` does not know Bangla. */
    private const BN_IMPERATIVE = '(?:পাঠাও|পাঠান|পাঠা|পাঠিয়ে দাও|পাঠিয়ে দিন|পাঠিয়ে দেন|দাও|দিন|দেন|দে|করো|করুন|করেন|কর|করে দাও|করে দিন|জানাও|জানান|জানিয়ে দাও|জানিয়ে দিন|বলো|বলুন|বলে দাও|বলে দিন|'
        .'(?:পাঠাতে|দিতে|করতে|জানাতে|বলতে) (?:পারবে|পারবেন|পারো|পারেন|পারবা))';

    private const BN_INTERROGATIVE = '(?:কয়টা|কয়টি|কতগুলো|কতটা|কত|কি|কী|কেন|কখন|কোন|কোথায়|কিনা|কেমন|কারা)';

    /** A Bangla noun may carry a case ending or a classifier: -কে, -দের, -এ, -য়, -টা, -টি, -গুলো. */
    private const BN_SUFFIX = '(?:কে|দের|দেরকে|ে|এ|য়|টা|টি|গুলো)?(?![\p{L}\p{M}])';

    /** @var array<string, string> kind => the noun that makes an imperative that kind */
    private const BN_NOUNS = [
        self::CUSTOMER_REPLY => '(?:কাস্টমার|গ্রাহক|ক্রেতা)[^।]*(?:উত্তর|রিপ্লাই|জবাব|রেসপন্স)',
        self::PUBLICATION => '(?:পোস্ট|প্রকাশ|পাবলিশ|আপলোড|স্টোরি|রিল|বিজ্ঞাপন)',
        self::OUTBOUND_MESSAGE => '(?:হোয়াটসঅ্যাপ|মেসেজ|ম্যাসেজ|বার্তা|এসএমএস|ইমেইল|ইমেল|মেইল|টেক্সট|ফোন|কল|সাপ্লায়ার|সরবরাহকারী|কুরিয়ার|ডিলার|তাদের|ওদের|তাকে|ওকে|সবাই)',
    ];

    private const BL_IMPERATIVE = '(?:pathao|pathan|pathay dao|pathiye dao|pathiye din|dao|din|den|de|koro|korun|koren|kor|kore dao|kore din|janao|janan|janiye dao|janiye din|bolo|bolun|bole dao|bole din|'
        .'(?:pathate|dite|korte|janate|bolte) (?:parbe|parben|paro|paren|parba))';

    private const BL_INTERROGATIVE = '(?:koyta|koiyta|koyti|kotogula|koto|keno|kobe|kokhon|kon|kothay|kina|kemon|ki|kara)';

    /** @var array<string, string> */
    private const BL_NOUNS = [
        self::CUSTOMER_REPLY => '(?:customer|kastomar|grahok|kreta)(?:\s*ke|\s*der)?\b.*\b(?:reply|uttor|jobab|response)',
        self::PUBLICATION => '(?:post|publish|upload|story|reel|ad|biggapon)',
        self::OUTBOUND_MESSAGE => '(?:whats ?app|message|msg|sms|email|mail|text|call|phone|supplier|courier|dealer|tader|oder|take|oke|sobaike)',
    ];

    /**
     * A clause that asks for INFORMATION: what the Executive is for. Only such a clause, beside a
     * flagged one, lets the run go on (the settled mixed case); any other remainder belongs to the
     * refused request.
     */
    private const INFORMATION = '/^(?:what|what\'s|whats|how|how\'s|which|who|whom|whose|when|where|why|whether|is|are|was|were|did|does|'
        .'tell me|give me|show me|let me know|send me|brief me|update me|remind me|walk me through|report|summari[sz]e|list|explain|describe|compare|'
        .'check|look up|read me|find|pull|break down|rank|total|count|analy[sz]e)\b/u';

    public function __construct(private readonly RolePolicy $policy) {}

    /**
     * @param  string|null  $role  the agent's `role_key`; a role the policy does not name forbids nothing
     */
    public function detect(string $trustedText, ?string $role): RoleBoundarySignal
    {
        try {
            // ⛔ A message no pattern can read is not a message that asked for nothing: invalid UTF-8
            //    makes every `/u` match fail quietly, which would read as "nothing found". Fail closed.
            if (! mb_check_encoding($trustedText, 'UTF-8')) {
                throw new RuntimeException('the message is not valid UTF-8, so no pattern can be trusted to have read it');
            }

            $forbiddenForRole = $this->policy->forbiddenFor($role);
            $kinds = [];
            $forbidden = [];
            $evidence = [];
            $informationRemainder = false;

            foreach ($this->clauses($trustedText) as $clause) {
                $found = $this->kindsIn($clause);

                if ($found === []) {
                    if ($this->asksForInformation($clause)) {
                        $informationRemainder = true;
                    }

                    continue;
                }

                foreach ($found as $kind) {
                    if (! in_array($kind, $kinds, true)) {
                        $kinds[] = $kind;
                    }
                    $isForbidden = in_array($kind, $forbiddenForRole, true);
                    if ($isForbidden && ! in_array($kind, $forbidden, true)) {
                        $forbidden[] = $kind;
                    }
                    $evidence[] = ['kind' => $kind, 'clause' => mb_substr($clause, 0, 160), 'forbidden' => $isForbidden];
                }
            }

            // The same for a pattern the engine gave up on (a backtracking limit): no answer is not "no".
            if (preg_last_error() !== PREG_NO_ERROR) {
                throw new RuntimeException('a pattern could not be evaluated: '.preg_last_error_msg());
            }

            return new RoleBoundarySignal(self::VERSION, $this->policy->version(), $role, $kinds, $forbidden, $evidence, $informationRemainder);
        } catch (Throwable $e) {
            // ⛔ Fail closed: a detector that cannot say "permitted" says "forbidden", with its error on the row.
            return new RoleBoundarySignal(self::VERSION, RolePolicy::VERSION, $role, [self::DETECTOR_ERROR], [self::DETECTOR_ERROR], [], false,
                get_class($e).': '.$e->getMessage());
        }
    }

    /**
     * The outbound kinds one clause ASKS for, whatever the role.
     *
     * @return list<string>
     */
    public function kindsIn(string $clause): array
    {
        $found = [];

        $english = $this->stripLeadIns($clause);
        foreach (self::ENGLISH as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $english) === 1) {
                    $found[] = $kind;
                    break;
                }
            }
        }

        // A customer reply is also a message; one kind is enough, the narrower one.
        if (in_array(self::CUSTOMER_REPLY, $found, true)) {
            $found = array_values(array_diff($found, [self::OUTBOUND_MESSAGE]));
        }

        foreach ([
            [self::BN_NOUNS, self::BN_IMPERATIVE, self::BN_INTERROGATIVE, self::BN_SUFFIX],
            [self::BL_NOUNS, self::BL_IMPERATIVE, self::BL_INTERROGATIVE, '(?![\p{L}\p{M}])'],
        ] as [$nouns, $imperative, $interrogative, $suffix]) {
            if (! $this->endsInImperative($clause, $imperative) || $this->hasWord($clause, $interrogative)) {
                continue;
            }
            foreach ($nouns as $kind => $noun) {
                if (in_array($kind, $found, true)) {
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{M}])'.$noun.$suffix.'/u', $clause) === 1) {
                    $found[] = $kind;
                    // The narrowest kind the clause names, as for English.
                    if ($kind === self::CUSTOMER_REPLY) {
                        break;
                    }
                }
            }
        }

        if (in_array(self::CUSTOMER_REPLY, $found, true)) {
            $found = array_values(array_diff($found, [self::OUTBOUND_MESSAGE]));
        }

        return $found;
    }

    public function asksForInformation(string $clause): bool
    {
        return preg_match(self::INFORMATION, $this->stripLeadIns($clause)) === 1
            || str_ends_with(rtrim($clause), '?')
            || $this->hasWord($clause, self::BN_INTERROGATIVE)
            || $this->hasWord($clause, self::BL_INTERROGATIVE);
    }

    /**
     * The message as lower-cased clauses, split as `ProhibitedCapability` splits them, also at the
     * Bangla full stop and the Bangla and Banglish "then", and at a bare "and" when an action verb
     * follows it: "check the stock and WhatsApp the supplier" asks for two things, and the second
     * would otherwise never open a clause of its own.
     *
     * @return list<string>
     */
    public function clauses(string $text): array
    {
        $lower = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
        $parts = preg_split('/(?:[.;!?।]+(?=\s|$)|\s+(?:so|then|and then|and after that|after that|meanwhile)\s+|,\s+(?:and|so|then)\s+|\s+-\s+|,?\s+(?:তারপর|এরপর|এবং তারপর|tarpor|erpor)\s+|'
            .'\s+and\s+(?=(?:please\s+|then\s+|also\s+|just\s+)?'.self::ACTION_VERB.'\b))/u', $lower) ?: [$lower];

        return array_values(array_filter(array_map(static fn (string $p) => trim($p, " \t\n\r\0\x0B,\"'()"), $parts), static fn (string $p) => $p !== ''));
    }

    private function stripLeadIns(string $clause): string
    {
        $out = trim($clause);
        for ($i = 0; $i < 6; $i++) {
            $next = preg_replace(self::LEAD_IN, '', $out, 1);
            if ($next === null || $next === $out) {
                break;
            }
            $out = trim($next);
        }

        return $out;
    }

    private function endsInImperative(string $clause, string $imperative): bool
    {
        return preg_match('/(?<![\p{L}\p{M}])'.$imperative.'[\s?!।.]*$/u', $clause) === 1;
    }

    private function hasWord(string $clause, string $words): bool
    {
        return preg_match('/(?<![\p{L}\p{M}])'.$words.'(?![\p{L}\p{M}])/u', $clause) === 1;
    }
}
