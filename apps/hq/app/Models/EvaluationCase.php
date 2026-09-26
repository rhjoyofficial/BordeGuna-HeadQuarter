<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an agent should or should not do, with what SHOULD happen written down (ADR-023).
 *
 * Expected refusals matter as much as expected answers. A corpus that only checks correct answers
 * cannot notice a model that has become helpful about the wrong things, which is the exact failure
 * mode that matters once a model reads customer messages.
 */
class EvaluationCase extends Model
{
    protected $fillable = [
        'suite_id', 'key', 'purpose', 'input', 'expectation', 'grader', 'grader_config',
        'severity', 'dimension', 'applies_to_role', 'tags', 'is_active',
        'retired_at', 'retirement_reason', 'replaced_by_key',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'expectation' => 'array',
            'grader_config' => 'array',
            'tags' => 'array',
            'is_active' => 'boolean',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /**
     * docs/88 point 15: a case retired as a bad case is kept, with its results, and never evaluated
     * again. Retirement is recorded, not a delete and not an edit of the case itself.
     */
    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(EvaluationSuite::class, 'suite_id');
    }

    public function expectsRefusal(): bool
    {
        return (bool) ($this->expectation['refusal'] ?? false);
    }

    /**
     * The boundary must be recognised BEFORE any evidence is gathered: the agent names no tool at
     * all, executed or not (ADR-038). Stronger than `must_not_call`, which forbids particular tools
     * and says nothing about a five-call sweep of permitted ones made while deciding.
     */
    public function expectsNoToolCalls(): bool
    {
        return (bool) ($this->expectation['no_tool_calls'] ?? false);
    }

    /**
     * Every call the agent proposes must be one the control plane can run (docs/67): scoped to a
     * business it named correctly, argued as the schema says. The in-role half of tool selection;
     * meaningful only on a case that expects an answer.
     */
    public function expectsNoDeniedCalls(): bool
    {
        return (bool) ($this->expectation['no_denied_calls'] ?? false);
    }

    /**
     * Whether the runtime should have established a trusted task for this case (ADR-041): true for
     * an in-role question, false for a bare continuation, a dependent remainder or an authority
     * request. Null when the case does not say, and then the check is not applicable.
     */
    public function expectsTask(): ?bool
    {
        $value = $this->expectation['expects_task'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * A clarification satisfies this case's refusal expectation (ADR-040): the case is about a
     * message with no task in it, and asking what the task is serves it as well as declining does.
     * Never set on a case whose refusal is genuinely required; those keep refuse as the only verdict.
     */
    /**
     * ADR-042. `true`: the runtime's detector must have flagged the message; a list: at least one of
     * those classes; `false`: the detector must NOT have flagged it (a legitimate question about
     * security). Null when the case says nothing.
     *
     * @return bool|list<string>|null
     */
    public function expectsSecurityIntent(): bool|array|null
    {
        $value = $this->expectation['expects_security_intent'] ?? null;
        if (is_bool($value)) {
            return $value;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /**
     * Whether the runtime's prohibited-capability detector should flag this case's trusted text
     * (ADR-044): true, false, or the kinds of which at least one must be found. Null when the case
     * does not say.
     *
     * @return bool|list<string>|null
     */
    public function expectsProhibitedCapability(): bool|array|null
    {
        $value = $this->expectation['expects_prohibited_capability'] ?? null;
        if (is_bool($value)) {
            return $value;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /**
     * Whether the runtime should find an outbound action this agent's role forbids (docs/86): true,
     * false, or the list of kinds of which at least one must be found. Null: not applicable.
     *
     * @return bool|list<string>|null
     */
    public function expectsRoleBoundary(): bool|array|null
    {
        $value = $this->expectation['expects_role_boundary'] ?? null;
        if (is_bool($value)) {
            return $value;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /**
     * The verdict must be the runtime's, reached before the model was asked (docs/86): the run ended
     * refused or clarified with zero model turns.
     */
    public function expectsRuntimeVerdict(): bool
    {
        return (bool) ($this->expectation['expects_runtime_verdict'] ?? false);
    }

    public function acceptsClarify(): bool
    {
        return (bool) ($this->expectation['accept_clarify'] ?? false);
    }

    /**
     * A group-wide question must become one scoped call per relevant business (ADR-040):
     * `{tool, at_least}` names the tool and how many distinct businesses it must have been called for.
     *
     * @return array{tool: string, at_least: int}|null
     */
    public function coversBusinesses(): ?array
    {
        $rule = $this->expectation['covers_businesses'] ?? null;
        if (! is_array($rule) || ! is_string($rule['tool'] ?? null)) {
            return null;
        }

        return ['tool' => $rule['tool'], 'at_least' => max(1, (int) ($rule['at_least'] ?? 1))];
    }

    /**
     * A single-business question must stay single-target (ADR-043): `{tool, within}` names the
     * tool and the business slug every executed call of it must have landed in.
     *
     * @return array{tool: string, within: string}|null
     */
    public function scopeBound(): ?array
    {
        $rule = $this->expectation['scope_bound'] ?? null;
        if (! is_array($rule) || ! is_string($rule['tool'] ?? null) || ! is_string($rule['within'] ?? null)) {
            return null;
        }

        return ['tool' => $rule['tool'], 'within' => $rule['within']];
    }

    /**
     * Which of the two safety questions this case asks (docs/69): can unauthorised input create
     * AUTHORITY, or can the agent invent, inherit or continue a TASK nobody authorised? A third
     * family, trusted-content analysis, holds the cases where a trusted caller asks about untrusted
     * content and an answer is the right verdict. Declared with a `family:` tag; inferred from the
     * older tags for cases recorded before the distinction existed, so that history is reported
     * under it without being edited.
     */
    public function family(): ?string
    {
        $tags = array_values(array_filter((array) $this->tags, 'is_string'));

        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'family:')) {
                return substr($tag, 7);
            }
        }

        if (in_array('invention', $tags, true) || in_array('remainder', $tags, true) || in_array('bare', $tags, true)) {
            return 'task_integrity';
        }
        // ⭐ ADR-044: a request for a capability HQ prohibits (SQL, a shell, a secret) is its own
        //    family, measured apart from authority. Gate 9 reported the SQL case under authority
        //    safety, where it could only read as an authority failure, which it was not.
        if (in_array('sql', $tags, true) || in_array('shell', $tags, true) || in_array('credential', $tags, true)
            || in_array('execution', $tags, true)) {
            return 'capability_safety';
        }
        if (in_array('bypass', $tags, true) || in_array('reassignment', $tags, true) || in_array('injection', $tags, true)
            || in_array('scope', $tags, true)) {
            return 'authority_safety';
        }

        return null;
    }

    /**
     * Gate P3-G2 (docs/77 C.4): whether the conversation core should have handed the thread to the
     * support agent at all. Null when the case does not say.
     */
    public function expectsAgentWoken(): ?bool
    {
        $value = $this->expectation['agent_woken'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** How many reply proposals the case allows: `one`, `none`, `at_most_one`, or null when it does not say. */
    public function expectedReplies(): ?string
    {
        $value = $this->expectation['replies'] ?? null;

        return in_array($value, ['one', 'none', 'at_most_one'], true) ? $value : null;
    }

    /** The script a proposed reply must be written in: `bangla`, `latin`, or null. */
    public function expectedReplyScript(): ?string
    {
        $value = $this->expectation['reply_script'] ?? null;

        return in_array($value, ['bangla', 'latin'], true) ? $value : null;
    }

    /**
     * The only tools the agent may name on this case, or null when the case does not restrict them.
     *
     * @return list<string>|null
     */
    public function onlyTools(): ?array
    {
        $value = $this->expectation['only_tools'] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /** @return list<string> tools this case says must NOT be called, whatever else happens */
    public function forbiddenTools(): array
    {
        $tools = $this->expectation['must_not_call'] ?? [];

        return is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [];
    }

    public function isBlocker(): bool
    {
        return $this->severity === 'blocker';
    }
}
