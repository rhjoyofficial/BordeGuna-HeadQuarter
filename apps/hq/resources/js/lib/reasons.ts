import { currentLocale, type Locale, translate } from '@/lib/i18n';

/**
 * Reason codes to words (ruling 1 Nov 2026, binding).
 *
 * The server keeps a STABLE canonical code for every refusal and reason it hands a screen (a gateway outcome
 * class, a consent refusal, a group-wide switch refusal, an approval decision refusal, an execution-context
 * refusal, an agent's stop reason). This file maps a KNOWN code to an approved sentence: the English below is
 * also the key of its Bangla in `i18n/bn.json`, so the reader's language decides which one shows.
 *
 *   - known code    -> the approved message (and a short label for tables), code kept for operators;
 *   - unknown code  -> "This action was refused. Details for operators: {code}" in the reader's language;
 *   - free text only (a reason with no code yet) -> the server's own English sentence on an English screen,
 *     and the generic refusal on a Bangla screen with the English kept under "Details for operators".
 *
 * ⛔ Nothing here translates free text, by a model or otherwise: only mapped codes get words.
 * Held by tests/Feature/Foundation/BanglaTimeAndReasonsTest.php (every code has its Bangla, both the label and
 * the message).
 */
export type ReasonEntry = { label: string; message: string };

export const REASONS: Record<string, ReasonEntry> = {
    // ── Gateway outcome classes (App\Gateway\OutcomeClass): whose fault a call that did not succeed was ──
    policy_denied: { label: 'Policy denied', message: 'A policy refused this action, so nothing left HQ.' },
    permission_denied: { label: 'Permission denied', message: 'The permission this action needs is not held, so nothing left HQ.' },
    consent_denied: { label: 'Consent denied', message: 'The customer has not given consent for this, so nothing left HQ.' },
    autonomy_denied: { label: 'Autonomy denied', message: 'The agent is not allowed to do this on its own, so nothing left HQ.' },
    scope_denied: { label: 'Outside scope', message: 'This is outside the businesses or brands this run may touch, so nothing left HQ.' },
    approval_required: { label: 'Approval required', message: 'This action needs a person to approve it first.' },
    invalid_arguments: { label: 'Invalid input', message: 'The request did not have the shape the tool needs, so nothing left HQ.' },
    capability_unavailable: { label: 'Not available', message: 'This capability is not available in HQ yet, so nothing left HQ.' },
    feature_disabled: {
        label: 'Feature switched off',
        message: 'This feature is switched off, so nothing ran. A person with group-wide authority can switch it on.',
    },
    content_policy_rejected: { label: 'Content rejected', message: "The content did not pass HQ's content check, so it was not sent." },
    credential_missing: { label: 'Credential missing', message: 'The key HQ needs to reach this system is not set up.' },
    credential_invalid: { label: 'Credential invalid', message: "The system rejected HQ's key. The key needs to be replaced." },
    provider_unauthorized: { label: 'Provider refused access', message: "The outside system refused HQ's access." },
    provider_unavailable: { label: 'Provider unavailable', message: 'The outside system did not answer. Try again later.' },
    provider_blocked_by_credit: {
        label: 'Provider out of credit',
        message: 'The outside system refused because the account behind the key has no funds.',
    },
    connector_error: { label: 'Connector error', message: 'The outside system answered with an error.' },
    network_error: { label: 'Network error', message: 'HQ could not reach the outside system over the network.' },
    timeout: { label: 'Timed out', message: 'The outside system took too long to answer.' },
    internal_error: { label: 'HQ error', message: 'Something went wrong inside HQ. The operators have the details.' },
    uncertain: { label: 'Outcome unknown', message: 'HQ cannot tell whether this went through. Check before trying again.' },

    // ── Consent refusals (App\Consent\ConsentRefusal) ──
    consent_do_not_contact: { label: 'Do not contact', message: 'This customer must not be contacted.' },
    consent_withdrawn: { label: 'Withdrawn', message: 'This customer withdrew consent.' },
    consent_expired: { label: 'Expired', message: "This customer's consent has expired." },
    consent_opt_in_missing: { label: 'No opt-in', message: "This purpose needs the customer's own opt-in, and there is none." },
    consent_not_recorded: { label: 'Nothing recorded', message: 'No consent is recorded for this customer. Silence is never consent.' },
    consent_subject_unresolved: {
        label: 'Unknown person',
        message: 'HQ could not tell which customer this is about, so consent could not be checked.',
    },

    // ── AI runtime and agent stop reasons ──
    ai_paused: { label: 'AI paused', message: 'Pause All AI is on, so no agent can act until a person turns AI back on.' },
    ai_runtime_off: { label: 'AI runtime off', message: "HQ's AI runtime is switched off, so no agent can answer yet." },
    'kill_switch.ai_paused': { label: 'AI paused', message: 'Pause All AI is on, so no agent can act until a person turns AI back on.' },
    output_truncated: { label: 'Answer cut off', message: "The model's answer was cut off before it finished, so it was not used." },
    provider_refused: { label: 'Model refused', message: 'The model provider refused to answer.' },
    repeated_denial: { label: 'Refused repeatedly', message: 'The agent kept proposing actions HQ refused, so the run was stopped.' },
    model_error: { label: 'Model error', message: 'The model provider returned an error.' },

    // ── Group-wide switches (App\Support\Authorization\GroupWideAuthority) ──
    group_switch_no_person: {
        label: 'No person named',
        message: 'This switch acts on every business, and no person was named, so nobody holds group-wide authority for it.',
    },
    group_switch_account_disabled: { label: 'Account disabled', message: 'This switch acts on every business, and your account is disabled.' },
    group_switch_permission_missing: {
        label: 'Permission missing',
        message: 'This switch acts on every business, and you do not hold the permission it needs.',
    },
    group_switch_scope_unreadable: {
        label: 'Scope unreadable',
        message: 'This switch acts on every business, and your business scope could not be read, so it is refused.',
    },
    group_switch_business_restricted: {
        label: 'Limited to some businesses',
        message:
            'This switch acts on every business, and your access is limited to some businesses. Only a person with group-wide access may change it.',
    },

    // ── Approval decisions (App\Models\Approval::DECISION_REFUSAL_CODES) ──
    approval_do_plan_self: {
        label: 'Your own plan',
        message:
            'This is a step of a Do plan you started or that an agent proposed under your authority, so somebody else decides it. You may still withdraw it.',
    },
    approval_requester_must_not_decide: {
        label: 'You asked for it',
        message: 'You asked for this, and this action must be decided by somebody other than the person who asked. You may still withdraw it.',
    },
    approval_acts_under_other_authority: {
        label: "Another person's authority",
        message:
            "You asked for this through an agent that acts under another person's authority, so the decision belongs to somebody else. You may still withdraw it.",
    },
    approval_permission_missing: { label: 'Permission missing', message: 'You do not hold the permission to decide approvals.' },
    approval_delegated_elsewhere: { label: 'Delegated', message: 'This approval is delegated to somebody else.' },

    // ── Execution context (App\Execution\ExecutionContextRefused) and Ask HQ ──
    agent_unknown: { label: 'Unknown agent', message: 'There is no such agent.' },
    agent_not_active: { label: 'Agent not active', message: 'This agent is not active, so it will not run.' },
    agent_no_enabler: { label: 'No enabling person', message: 'This agent names no person whose authority it borrows, so it cannot run.' },
    agent_enabler_missing: {
        label: 'Enabling person gone',
        message: 'The person whose authority this agent borrows no longer exists, so it cannot run.',
    },
    agent_enabler_disabled: {
        label: 'Enabling person disabled',
        message: 'The person whose authority this agent borrows is disabled, so it cannot run.',
    },
    requester_missing: { label: 'No requester', message: 'The request names nobody asking, so nothing runs.' },
    requester_unaccountable: { label: 'Nobody accountable', message: 'Nobody accountable is recorded for this request, so nothing runs.' },
    requester_gone: { label: 'Requester gone', message: 'The person who asked no longer exists, so nothing runs.' },
    requester_disabled: { label: 'Requester disabled', message: 'The person who asked is disabled, so nothing runs in their name.' },
    scope_no_shared_business: {
        label: 'No shared business',
        message: "You, the agent's enabling person and the agent share no business, so there is nothing this could be about.",
    },
    business_outside_scope: {
        label: 'Business outside scope',
        message: "That business is outside what you and the agent's enabling person may both read.",
    },
    no_common_tool_permissions: {
        label: 'No shared permissions',
        message: "You and the agent's enabling person hold in common none of the permissions its tools need, so the question is not put to it.",
    },
};

/** The generic words for a code nobody mapped yet. The code itself is the detail an operator needs. */
export const UNKNOWN_REASON = 'This action was refused. Details for operators: {code}';

/** The generic words for a refusal that came with no code at all (free text only). */
export const UNCODED_REASON = 'This action was refused.';

const CODE_SHAPE = /^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/;

/** Whether a string is shaped like a canonical reason code (`feature_disabled`, `kill_switch.ai_paused`). */
export function isReasonCode(value: string | null | undefined): value is string {
    return typeof value === 'string' && CODE_SHAPE.test(value);
}

export type ReasonInput = { code?: string | null; text?: string | null } | string | null | undefined;

export type ReasonView = {
    /** What the reader sees, in their language. */
    message: string;
    /** The canonical code, for operators; null when the server sent none. */
    code: string | null;
    /** The server's own English sentence, kept for operators when it is not what `message` already says. */
    detail: string | null;
    /** Whether the code is one this file maps. */
    known: boolean;
};

function normalise(input: ReasonInput): { code: string | null; text: string | null } {
    if (input === null || input === undefined) return { code: null, text: null };
    if (typeof input === 'string') return isReasonCode(input) ? { code: input, text: null } : { code: null, text: input };
    const code = input.code ?? null;
    const text = input.text ?? null;
    // A code-shaped text with no code is a code (older props carried the code in the text field).
    if (code === null && isReasonCode(text)) return { code: text, text: null };
    return { code, text };
}

/** A reason in the reader's language: a mapped code's approved message, or the safe generic one. Null for nothing. */
export function describeReason(input: ReasonInput, locale: Locale = currentLocale()): ReasonView | null {
    const { code, text } = normalise(input);
    if (code === null && (text === null || text.trim() === '')) return null;

    if (code !== null) {
        const entry = REASONS[code];
        const message = entry ? translate(locale, entry.message) : translate(locale, UNKNOWN_REASON, { code });
        return { message, code, detail: text !== null && text !== entry?.message ? text : null, known: entry !== undefined };
    }

    // Free text only: English as the server wrote it on an English screen; never machine-translated.
    if (locale === 'en') return { message: text as string, code: null, detail: null, known: false };
    return { message: translate(locale, UNCODED_REASON), code: null, detail: text, known: false };
}

/** A code's short label for a table cell, in the reader's language; an unknown code reads as itself, flagged as unrecognised. */
export function reasonLabel(code: string | null | undefined, locale: Locale = currentLocale()): string {
    if (!code) return '-';
    const entry = REASONS[code];
    if (entry) return translate(locale, entry.label);
    return translate(locale, 'Unrecognised reason: {code}', { code });
}
