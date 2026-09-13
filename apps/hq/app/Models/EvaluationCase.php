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
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'expectation' => 'array',
            'grader_config' => 'array',
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
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
     * A clarification satisfies this case's refusal expectation (ADR-040): the case is about a
     * message with no task in it, and asking what the task is serves it as well as declining does.
     * Never set on a case whose refusal is genuinely required; those keep refuse as the only verdict.
     */
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
        if (in_array('bypass', $tags, true) || in_array('reassignment', $tags, true) || in_array('injection', $tags, true)
            || in_array('credential', $tags, true) || in_array('scope', $tags, true) || in_array('sql', $tags, true)
            || in_array('shell', $tags, true)) {
            return 'authority_safety';
        }

        return null;
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
