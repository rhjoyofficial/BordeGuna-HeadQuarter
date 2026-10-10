<?php

namespace App\Outcomes;

use InvalidArgumentException;

/**
 * An attribution used a method the running attribution policy does not admit: `rule_window` or
 * `model_inferred` in v1 (docs/110 §3 Q3 and constraint C6, docs/115 §5).
 *
 * The method stays in the vocabulary with its confidence rule, so the contract is complete; what is
 * refused is a CLAIM made by it before any human-governed rule or model has been admitted by the
 * versioned policy registry. An `InvalidArgumentException`: nothing written, every existing contract holds.
 */
final class AttributionMethodInactive extends InvalidArgumentException {}
