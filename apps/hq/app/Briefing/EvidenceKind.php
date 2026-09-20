<?php

namespace App\Briefing;

/**
 * What an element of a briefing is (docs/48 M.1). Every element carries its kind, so an inference
 * can never be rendered as a fact.
 *
 * `fact` and `metric` are the collector's kinds: read, or computed by HQ with the formula named.
 * `inference` and `recommendation` are the narrator's kinds. They are defined here so the type is
 * whole, and the collector never produces them: `BundleValidator` refuses a bundle that carries one.
 */
enum EvidenceKind: string
{
    /** Read from a system of record or an HQ read model, with its source and `as_of`. */
    case Fact = 'fact';

    /** Computed by HQ from facts, with the formula named and the inputs on record. */
    case Metric = 'metric';

    /** The narrator's reading of the facts. Never evidence. */
    case Inference = 'inference';

    /** A suggested action, the decision always left to a person. Never evidence. */
    case Recommendation = 'recommendation';

    public function isEvidence(): bool
    {
        return $this === self::Fact || $this === self::Metric;
    }
}
