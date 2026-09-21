<?php

namespace App\Autonomy\Evidence;

use App\Gateway\Descriptor\AutonomyLevel;

/**
 * What the runner hands the resolver when an autonomy evidence policy is active (ADR-048).
 *
 * - `ceiling`: the highest level the active policy allows on the measured evidence. The resolver
 *   combines it by MINIMUM with every other ceiling, so it can only lower an answer. It is never a
 *   grant and never raises anything: with no matching grant the answer is still A0.
 * - The rates: given only for CLEAN evidence (the current prompt stamp, a passed gate, zero
 *   unmeasured cases, a recount that agrees with what the run stored, every suite that declares
 *   cases in the dimension run), floored to whole thousandths. A dimension whose evidence is not
 *   clean is absent, and an absent dimension never falls back to the overall figure.
 * - `record`: what the run and the decision store: the policy id, version and digest, the evidence
 *   digest and the run ids, the ceiling and its reasons.
 *
 * ⛔ Built only by `EvidenceProducer::forRunner()`. Null (no object at all) means no policy is
 * active, and then the resolver decides exactly as it did before ADR-048.
 */
final readonly class RunnerEvidence
{
    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, float>  $dimensionRates
     * @param  array<string, array{overall: float|null, dimensions: array<string, float>}>  $suiteRates
     */
    public function __construct(
        public AutonomyLevel $ceiling,
        public array $record,
        public ?float $agreementRate = null,
        public array $dimensionRates = [],
        public array $suiteRates = [],
    ) {}

    /** The sentence the resolver records beside the ceiling. */
    public function detail(): string
    {
        $version = $this->record['policy']['version'] ?? '?';

        return ($this->record['evidence_counted'] ?? false)
            ? "the autonomy evidence policy (version {$version}) allows at most ".strtoupper($this->ceiling->value).' on the measured evidence'
            : "the autonomy evidence policy (version {$version}) counted no evidence: ".($this->record['reason'] ?? 'no reason recorded');
    }
}
