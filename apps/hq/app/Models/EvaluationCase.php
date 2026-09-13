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
