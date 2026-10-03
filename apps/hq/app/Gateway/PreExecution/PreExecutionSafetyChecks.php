<?php

namespace App\Gateway\PreExecution;

use App\Conversation\Language\LanguageRequirement;
use App\Conversation\Language\ScriptPolicyValidator;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\ToolRequest;

/**
 * The checks on a call's CONTENT that run after the gateway has authorized it and before it executes
 * (docs/97 §6 D4, superseding docs/92 A.6's ordering): model output -> parsing -> runtime guards ->
 * Tool Gateway (availability, arguments, scope and the execution context, policy and permission,
 * consent, idempotency and replay, credential pre-flight) -> THESE -> execute -> record -> verify.
 *
 * ⛔ Authorization and content validity are separate questions. "May this caller do this?" is the
 * gateway's and is answered first; nothing here can make a refused call permitted, and a call these
 * checks refuse is never executed. A refusal here is its own outcome class
 * (`OutcomeClass::ContentPolicyRejected`), never a permission denial, so the runtime can tell "the
 * agent may not" from "the agent may, and this text may not go" and run its one repair (docs/92 A.5).
 *
 * A tool opts in through its descriptor (`preExecutionChecks`). Today there is one check:
 *
 * - `reply_script` (customer-facing reply tools): the reply text's prose must be in the script the
 *   thread requires, by `ScriptPolicyValidator`, against the requirement the runtime resolved from the
 *   conversation core and the active language policy and carried on the request
 *   (`ToolRequest::$languageRequirement`). No requirement on the request, nothing required: a person
 *   replying from the inbox, or a thread with no detected script, is not checked.
 */
final class PreExecutionSafetyChecks
{
    public const REPLY_SCRIPT = 'reply_script';

    public const CHECKS = [self::REPLY_SCRIPT];

    public function __construct(private readonly ScriptPolicyValidator $scripts) {}

    /** @param  array<string, mixed>  $args  the validated arguments the tool would receive */
    public function check(ToolDescriptor $descriptor, ToolRequest $request, array $args): PreExecutionVerdict
    {
        foreach ($descriptor->preExecutionChecks as $check) {
            if ($check === self::REPLY_SCRIPT && ($refusal = $this->replyScript($request, $args)) !== null) {
                return $refusal;
            }
        }

        return PreExecutionVerdict::passed();
    }

    /** @param  array<string, mixed>  $args */
    private function replyScript(ToolRequest $request, array $args): ?PreExecutionVerdict
    {
        $requirement = $request->languageRequirement === null ? null : LanguageRequirement::fromArray($request->languageRequirement);
        if ($requirement === null || ! is_string($args['text'] ?? null)) {
            return null;
        }

        $result = $this->scripts->validate($args['text'], $requirement);

        return $result->valid ? PreExecutionVerdict::passed($result->toArray())
            : PreExecutionVerdict::refused(self::REPLY_SCRIPT, '['.$request->toolKey.'] was refused before execution: '.$result->explain()
                .'. It has not run and will not be sent as written.', $result->toArray());
    }
}
