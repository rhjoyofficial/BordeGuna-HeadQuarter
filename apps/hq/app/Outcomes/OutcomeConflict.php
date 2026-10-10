<?php

namespace App\Outcomes;

use RuntimeException;

/**
 * The same idempotency key arrived with a different meaning, or a row was corrected twice at once.
 * The ledger never overwrites: a changed fact is a supersession, made deliberately (docs/107 section 6).
 */
final class OutcomeConflict extends RuntimeException {}
