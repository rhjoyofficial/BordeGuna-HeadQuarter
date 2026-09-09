<?php

namespace App\Evaluation;

use App\Models\EvaluationCase;
use App\Support\Features\Features;

/**
 * Grades against a written rubric, for the cases where structure cannot decide (ADR-023).
 *
 * Justified only where the question is genuinely about quality: was the tone right for a customer
 * who is angry, did the answer address what was actually asked, is the Bangla natural. Everything
 * that can be a yes-or-no question about tool calls or refusals belongs to `DeterministicGrader`,
 * because a yes-or-no question answered by a model is a yes-or-no question with a failure rate.
 *
 * ⛔ **It refuses to run while `ai.runtime` is off**, which in Phase 2.5 means it refuses always.
 * That is the correct behaviour rather than a limitation: this milestone ships with no model calls,
 * and a grader that quietly returned a passing score without grading anything would be worse than
 * one that says it cannot.
 */
final readonly class RubricGrader implements Grader
{
    public function __construct(private Features $features) {}

    public function key(): string
    {
        return 'rubric';
    }

    public function grade(EvaluationCase $case, EvaluationObservation $observation): GradeResult
    {
        if (! $this->features->enabled('ai.runtime')) {
            return GradeResult::fail(
                'rubric grading needs a model and ai.runtime is off, so this case was not graded. '
                .'It is recorded as unmet rather than passed: an ungraded case must never count as a pass.',
                $case->expectation,
                $observation->toArray(),
                null,
            );
        }

        // The judging call itself belongs to the phase that has an agent to judge. Until then this
        // is deliberately unreachable rather than half-implemented against a shape nobody has used.
        return GradeResult::fail(
            'rubric grading is declared but not yet implemented; it arrives with the first agent that needs it.',
            $case->expectation,
            $observation->toArray(),
            null,
        );
    }
}
