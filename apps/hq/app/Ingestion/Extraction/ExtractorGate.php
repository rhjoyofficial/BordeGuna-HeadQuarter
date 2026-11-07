<?php

namespace App\Ingestion\Extraction;

use App\Support\Features\Features;
use Throwable;

/**
 * Ruling 31 Oct 2026 item 10: the deterministic, rule-based extractor is the DEFAULT. A future OCR or model-assisted extractor is
 * allowed only behind its OWN feature flag AND a passed evaluation gate; both ship off, so today every bill is read by the rule-based
 * extractor whatever is bound.
 *
 *  - Flag `ingestion.model_extraction` (no row: OFF).
 *  - Evaluation gate `hq.ingestion.model_extraction.eval_passed_extractor`: the key of the ONE extractor that passed the evaluation
 *    (null ships). Naming it is a reviewed release with the evaluation evidence committed beside it, never a switch.
 *
 * Whatever extractor runs, its output passes the same `ExtractionVerifier` (citations with page and offsets, re-verified against the
 * source text) and the same deterministic `BillChecks`, and lands as a DRAFT a person must review field by field. A model-assisted
 * extractor must also call `AiPause` itself. No extractor posts an invoice or authorises a payment: none has a path to the ERP.
 */
final class ExtractorGate
{
    public const FLAG = 'ingestion.model_extraction';

    public function __construct(private readonly Features $features) {}

    public function select(BillExtractor $candidate): BillExtractor
    {
        if ($candidate instanceof RuleBasedBillExtractor) {
            return $candidate;
        }
        $passed = config('hq.ingestion.model_extraction.eval_passed_extractor');
        try {
            $on = $this->features->enabled(self::FLAG);
        } catch (Throwable) {
            $on = false;
        }

        return $on && is_string($passed) && $passed !== '' && $passed === $candidate->key() ? $candidate : new RuleBasedBillExtractor;
    }
}
