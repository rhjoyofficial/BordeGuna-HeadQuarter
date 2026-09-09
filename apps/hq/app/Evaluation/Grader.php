<?php

namespace App\Evaluation;

use App\Models\EvaluationCase;

/** Turns "what should have happened" and "what did" into a pass, a score and a reason. */
interface Grader
{
    public function key(): string;

    public function grade(EvaluationCase $case, EvaluationObservation $observation): GradeResult;
}
