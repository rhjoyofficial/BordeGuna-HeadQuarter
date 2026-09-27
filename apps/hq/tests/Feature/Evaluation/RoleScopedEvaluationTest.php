<?php

use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSubject;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationSuite;
use Database\Seeders\DomainSeeder;
use Database\Seeders\ExecutiveBehaviourSuiteSeeder;
use Database\Seeders\SafetyCorpusSeeder;

/*
 | Role-scoped evaluation, and the two layers (ADR-035, ADR-036).
 |
 | The failure being guarded is the one the first real gate run made: grading twelve customer-support
 | cases against an executive agent and reading 0.333 as evidence about the executive agent. A case is
 | only evidence about an agent whose role makes its expectation meaningful.
 |
 | ⛔ The most important test in this file is the COVERAGE one. Re-targeting the support-framed cases
 | is only legitimate because every invariant they carry is also tested role-neutrally. The day that
 | stops being true, re-targeting has quietly become deletion.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(SafetyCorpusSeeder::class);
    $this->seed(ExecutiveBehaviourSuiteSeeder::class);
});

/** A subject with a declared role that answers everything and calls nothing. */
function roleSubject(?string $role, bool $refuses = false): EvaluationSubject
{
    return new class($role, $refuses) implements EvaluationSubject
    {
        public function __construct(private ?string $role, private bool $refuses) {}

        public function subjectKey(): string
        {
            return 'subject:'.($this->role ?? 'none');
        }

        public function subjectRole(): ?string
        {
            return $this->role;
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            return new EvaluationObservation(refused: $this->refuses, answer: $this->refuses ? null : 'an answer');
        }
    };
}

function runSuite(string $key, EvaluationSubject $subject)
{
    return app(EvaluationRunner::class)->withGrader(new DeterministicGrader)
        ->run(EvaluationSuite::where('key', $key)->sole(), $subject, ['agent_key' => $subject->subjectKey()]);
}

// ── Coverage: re-targeting must never be a reduction ────────────────────────────────────────────

it('tests every invariant the support-framed cases carry against every role as well', function () {
    $core = EvaluationSuite::where('key', 'safety.core')->sole();

    $family = fn (EvaluationCase $c) => explode('.', $c->key)[0];

    $supportOnly = $core->cases()->where('applies_to_role', 'support')->get()->map($family)->unique();
    $everyRole = $core->cases()->whereNull('applies_to_role')->get()->map($family)->unique();

    // ⛔ Every invariant family that exists only in the support-framed set must also exist in the
    // role-neutral set. `shell` is the one exception in the other direction: the original corpus
    // filed it under `sql`, and splitting it out ADDS coverage rather than removing any.
    $missing = $supportOnly->reject(fn (string $f) => $everyRole->contains($f))->values()->all();

    expect($missing)->toBe([], 'invariant families tested only for support: '.implode(', ', $missing));
});

it('gives an executive agent MORE cases than the original corpus did, not fewer', function () {
    $core = EvaluationSuite::where('key', 'safety.core')->sole();

    $forExecutive = $core->cases()->whereNull('applies_to_role')->count()
        + EvaluationSuite::where('key', 'behaviour.executive')->sole()->cases()->count();

    // The original corpus was twelve. Re-targeting is only legitimate while this stays above it.
    expect($forExecutive)->toBeGreaterThan(12);
});

// ── Role filtering ──────────────────────────────────────────────────────────────────────────────

it('does not grade a support-framed case against an agent that is not a support agent', function () {
    $run = runSuite('safety.core', roleSubject('executive'));

    $graded = EvaluationResult::where('run_id', $run->id)->count();

    expect($run->cases_skipped)->toBe(12);
    expect($graded)->toBe($run->cases_total);
    expect($graded)->toBeGreaterThan(0);

    // The support-framed cases were not graded, so they cannot appear as failures.
    $keys = EvaluationResult::where('run_id', $run->id)->get()
        ->map(fn ($r) => EvaluationCase::find($r->case_id)->key);
    expect($keys)->not->toContain('injection.customer_message_instructs_tool_call');
});

it('grades those same cases against a support agent', function () {
    $run = runSuite('safety.core', roleSubject('support'));

    expect($run->cases_skipped)->toBe(0);
    // docs/91 ruling: a retired case is kept with its results by design; the corpus is its LIVE cases.
    expect($run->cases_total)->toBe(EvaluationSuite::where('key', 'safety.core')->sole()->cases()->whereNull('retired_at')->count());
});

it('refuses to grade a role-scoped suite against the wrong role, rather than passing it', function () {
    // ⛔ The single most misleading thing this subsystem could do is report "passed" for a suite that
    // matched nothing. It refuses instead.
    expect(fn () => runSuite('behaviour.executive', roleSubject('support')))
        ->toThrow(RuntimeException::class, 'scoped to role [executive]');

    expect(fn () => runSuite('behaviour.executive', roleSubject(null)))
        ->toThrow(RuntimeException::class, 'scoped to role [executive]');
});

it('records how many cases it skipped, so a thin run cannot look like a thorough one', function () {
    $run = runSuite('safety.core', roleSubject('executive'));

    // "12 cases, 0 graded" and "0 cases" must never read the same.
    expect($run->cases_skipped)->toBeGreaterThan(0);
    expect($run->cases_total + $run->cases_skipped)
        ->toBe(EvaluationSuite::where('key', 'safety.core')->sole()->cases()->whereNull('retired_at')->count()); // docs/91: live cases
});

// ── Per-check visibility ────────────────────────────────────────────────────────────────────────

it('reports must_not_call independently of the case verdict', function () {
    // A subject that answers everything: it calls nothing forbidden, and it fails every case that
    // expected a refusal. Exactly the shape of the first real gate run (docs/61).
    $run = runSuite('safety.core', roleSubject('executive'));

    expect($run->status)->toBe('failed');
    // ⭐ And yet must_not_call held everywhere. Under one number this fact was invisible.
    expect($run->check_rates['must_not_call']['failed'])->toBe(0);
    expect($run->check_rates['must_not_call']['passed'])->toBeGreaterThan(0);
    expect($run->check_rates['refusal']['failed'])->toBeGreaterThan(0);
});

it('records every check on each result, including the ones that did not apply', function () {
    $run = runSuite('safety.core', roleSubject('executive'));
    $result = EvaluationResult::where('run_id', $run->id)->first();

    expect($result->checks)->toHaveKeys(DeterministicGrader::CHECKS);
    expect($result->checks['citations'])->toBe('not_applicable');
});

it('still fails a case when any single check fails', function () {
    // The per-check record is for reporting what held. It is not a way for a case to be partly right.
    $run = runSuite('safety.core', roleSubject('executive'));

    expect($run->cases_failed)->toBeGreaterThan(0);
    expect($run->status)->toBe('failed');
});

// ── The anti-overfitting shape of the executive suite ───────────────────────────────────────────

it('makes refusing everything fail the executive suite, and answering everything fail it too', function () {
    // ⛔ A suite of only "refuse this" cases has a trivial cheat: refuse everything. This asserts the
    // suite is built so neither extreme can pass it.
    $refusesEverything = runSuite('behaviour.executive', roleSubject('executive', refuses: true));
    $answersEverything = runSuite('behaviour.executive', roleSubject('executive', refuses: false));

    expect($refusesEverything->status)->toBe('failed');
    expect($answersEverything->status)->toBe('failed');

    // And they fail on DIFFERENT cases, which is what proves the suite discriminates rather than
    // simply being hard. The one permitted overlap is a case that demands evidence coverage
    // (`covers_businesses`, ADR-040): a mute answer cannot name two businesses, so the answering
    // extreme fails it as well as the refusing one. That overlap is exactly those cases, no more.
    expect($refusesEverything->cases_failed)->toBeGreaterThan(0);
    expect($answersEverything->cases_failed)->toBeGreaterThan(0);

    $failedBy = fn ($run) => EvaluationResult::where('run_id', $run->id)->where('passed', false)->pluck('case_id');
    $overlap = $failedBy($refusesEverything)->intersect($failedBy($answersEverything))->sort()->values()->all();
    $needsCoverage = EvaluationSuite::where('key', 'behaviour.executive')->sole()->cases()->get()
        ->filter(fn (EvaluationCase $c) => $c->coversBusinesses() !== null)->pluck('id')->sort()->values()->all();

    expect($needsCoverage)->not->toBe([]);
    expect($overlap)->toBe($needsCoverage);
    expect($refusesEverything->cases_failed + $answersEverything->cases_failed)
        ->toBe($refusesEverything->cases_total + count($overlap));
});

it('includes paraphrases, so passing cannot depend on recognising a phrase', function () {
    foreach (['safety.core', 'behaviour.executive'] as $key) {
        $paraphrases = EvaluationSuite::where('key', $key)->sole()
            ->cases()->where('key', 'like', '%.paraphrase')->count();

        expect($paraphrases)->toBeGreaterThan(0, "[{$key}] has no paraphrase cases");
    }
});

it('keeps the executive suite permanent and every case a blocker', function () {
    $suite = EvaluationSuite::where('key', 'behaviour.executive')->sole();

    expect($suite->is_permanent)->toBeTrue();
    expect($suite->role_key)->toBe('executive');
    expect($suite->cases()->where('severity', '!=', 'blocker')->count())->toBe(0);
    expect(fn () => $suite->delete())->toThrow(LogicException::class, 'permanent');
});

it('keeps the original twelve present, active and unmodified in their expectations', function () {
    // Re-targeting is a tag. It is not a deactivation, and it is not an edit to what they expect.
    $twelve = EvaluationSuite::where('key', 'safety.core')->sole()
        ->cases()->where('applies_to_role', 'support')->get();

    expect($twelve)->toHaveCount(12);
    expect($twelve->every(fn ($c) => $c->is_active))->toBeTrue();
    expect($twelve->every(fn ($c) => $c->severity === 'blocker'))->toBeTrue();
    expect($twelve->firstWhere('key', 'credential.direct_request')->expectation['refusal'])->toBeTrue();
});
