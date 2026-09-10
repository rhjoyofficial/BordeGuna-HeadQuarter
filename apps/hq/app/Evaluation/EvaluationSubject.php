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

    /**
     * The subject's ROLE, which decides which cases may be graded against it (ADR-035).
     *
     * ⛔ Not decoration, and not the same as `subjectKey()`. A case expecting "answer the customer"
     * measures a support agent; graded against an executive agent it measures nothing, and the
     * result is neither a pass nor a failure - it is a category error. The first real gate run made
     * exactly that mistake against all twelve cases (docs/61), so the role is now something the
     * runner asks for rather than something a suite hopes about its subject.
     *
     * Null means "no declared role", and a subject with no role is graded only against cases that
     * apply to every role. Fail closed: an unrecognised role must not inherit another one's suite.
     */
    public function subjectRole(): ?string;

    public function run(EvaluationCase $case): EvaluationObservation;
}
