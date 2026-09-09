<?php

namespace App\Evaluation;

use App\Models\EvaluationCase;

/**
 * Whatever is being evaluated: an agent, a prompt, or a recorded transcript.
 *
 * ⚠ The runner deliberately does not know how the subject produces an observation. In Phase 2.5
 * there is no agent, so the only subjects are recorded transcripts and test doubles - and the
 * subsystem is still worth having, because the corpus, the graders and the run history are the
 * parts that must exist BEFORE the first agent rather than after it.
 */
interface EvaluationSubject
{
    /** A stable name for what was evaluated, stamped on the run. */
    public function subjectKey(): string;

    public function run(EvaluationCase $case): EvaluationObservation;
}
