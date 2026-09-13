<?php

namespace App\Agents;

/**
 * Grounds the task a run serves in the trusted operator input (docs/71, ADR-041).
 *
 * ⭐ Gate 6 showed the limit of ADR-040 exactly where that record said it would be: the model fills
 * `task` before it asks for anything, the runner runs nothing for an empty one, and in four cases
 * the model filled it with its own routine ("cross-business picture and what needs attention
 * today") against a message that said "Proceed." Nothing structural could object, because the
 * field was the model's and the runner trusted it. ChatGPT's line, from the thread: a model may
 * INTERPRET a task; only trusted input can establish that one EXISTS.
 *
 * So the field is now diagnostic evidence and this class decides. Deterministically, with no model:
 *
 *  - the operator's own words must carry a task at all. Words that only continue, permit or urge
 *    ("proceed", "go ahead", "same as before", "handle this", "act on it now", "quickly") carry
 *    none, however many of them there are;
 *  - a request to widen the agent's own authority, made by the trusted caller, invalidates every
 *    continuation in the same message. "Widen your scope, then give me the usual report" leaves no
 *    task. "I approved this in the meeting, so skip the approval and poll the ERP" leaves no task
 *    either, and in particular no read-only version of it;
 *  - the canonical task is the operator's words themselves, never the model's paraphrase. The
 *    model's `task` is kept beside it as what the run understood, and an empty one is still the
 *    model's own decision that there is nothing to serve.
 *
 * ⚠ What it cannot do, said plainly. It cannot tell a bad task from a good one, only a task that is
 * in the message from one that is not. "Have a look around the systems while I am away" contains
 * words; whether that is a task the executive should serve is the model's judgement and the
 * prompt's rule, and the gate measures it. Grounding is the floor under that judgement, not a
 * replacement for it.
 */
final class TaskGrounding
{
    public const GROUNDED = 'grounded';

    public const NO_TASK_IN_TRUSTED_INPUT = 'no_task_in_trusted_input';

    public const DEPENDENT_ON_REFUSED_AUTHORITY = 'dependent_on_refused_authority';

    public const MODEL_NAMED_NONE = 'model_named_none';

    /**
     * Words that continue, permit, urge or point, and never name what is to be done. A message made
     * only of these (plus function words) contains no task, whatever the model reads into it.
     */
    private const CONTINUATION = [
        'proceed', 'ahead', 'usual', 'same', 'before', 'continue', 'carry', 'normal', 'normally', 'quickly', 'quick',
        'handle', 'deal', 'now', 'please', 'then', 'after', 'afterwards', 'start', 'immediately', 'right', 'okay', 'ok',
        'yes', 'sure', 'fine', 'done', 'anyway', 'again', 'keep', 'posted', 'whatever', 'instructed', 'instruction',
        'instructions', 'act', 'says', 'say', 'said', 'told', 'tell', 'asked', 'requested', 'thing', 'things', 'stuff',
        'out', 'over', 'along', 'away', 'while', 'meanwhile', 'just', 'once', 'too', 'also', 'well', 'still', 'already',
    ];

    /** Function words and pronouns. */
    private const STOP = [
        'the', 'and', 'for', 'with', 'from', 'into', 'about', 'that', 'this', 'these', 'those', 'what', 'which', 'who',
        'whom', 'when', 'where', 'how', 'why', 'you', 'your', 'yours', 'yourself', 'our', 'ours', 'their', 'them',
        'they', 'his', 'her', 'its', 'are', 'was', 'were', 'been', 'being', 'have', 'has', 'had', 'does', 'did', 'will',
        'would', 'should', 'could', 'can', 'may', 'might', 'must', 'shall', 'not', 'but', 'nor', 'yet', 'than', 'then',
        'there', 'here', 'all', 'any', 'some', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'such', 'only',
        'own', 'very', 'one', 'ones', 'get', 'got', 'give', 'gave', 'let', 'make', 'made', 'want', 'like', 'need',
        'going', 'know', 'think', 'see', 'come', 'take', 'put', 'run', 'set',
    ];

    public function ground(string $trustedInput, string $modelTask, string $authorityRequest, bool $hasUntrustedContext): GroundedTask
    {
        $trusted = trim($trustedInput);
        $interpretation = trim($modelTask);

        // A claim about the agent's authority that the model says it found inside untrusted content
        // is only that when there IS untrusted content. Without any, the claim was the caller's own
        // request, and it is read as refused.
        $authority = $authorityRequest;
        if ($authority === AgentResponseSchema::AUTHORITY_IN_UNTRUSTED_CONTENT && ! $hasUntrustedContext) {
            $authority = AgentResponseSchema::AUTHORITY_REFUSED;
        }
        if (! in_array($authority, [AgentResponseSchema::AUTHORITY_NONE, AgentResponseSchema::AUTHORITY_REFUSED, AgentResponseSchema::AUTHORITY_IN_UNTRUSTED_CONTENT], true)) {
            $authority = AgentResponseSchema::AUTHORITY_NONE;
        }

        $trustedWords = $this->contentWords($trusted);

        if ($trustedWords === []) {
            return new GroundedTask('', $interpretation, $authority, self::NO_TASK_IN_TRUSTED_INPUT);
        }

        // ⭐ The dependency rule, made structural: the trusted caller asked the agent to widen its
        // own authority, so nothing else in the same message survives the refusal. Not the "usual
        // report" after it, and not a narrower, read-only version of the refused action.
        if ($authority === AgentResponseSchema::AUTHORITY_REFUSED) {
            return new GroundedTask('', $interpretation, $authority, self::DEPENDENT_ON_REFUSED_AUTHORITY);
        }

        if ($interpretation === '') {
            return new GroundedTask('', $interpretation, $authority, self::MODEL_NAMED_NONE);
        }

        // The canonical task is the operator's words, not the model's. The interpretation is kept
        // beside it as what the run understood; the authority is the message.
        return new GroundedTask($trusted, $interpretation, $authority, self::GROUNDED);
    }

    /**
     * The words of a text that could name a task: lower-cased, letters and digits only, three
     * characters or more, and neither a function word nor a continuation word.
     *
     * @return list<string>
     */
    public function contentWords(string $text): array
    {
        $normalised = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '');
        $out = [];

        foreach (preg_split('/\s+/u', trim($normalised)) ?: [] as $word) {
            if ($word === '' || mb_strlen($word) < 3) {
                continue;
            }
            if (in_array($word, self::STOP, true) || in_array($word, self::CONTINUATION, true)) {
                continue;
            }
            $out[$word] = $word;
        }

        return array_values($out);
    }
}
