<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * A HARD cap was reached and the call was refused.
 *
 * ⚠ Per decision V.8 Phase 2 ships SOFT caps only, which downgrade and notify rather than refuse, so
 * this is thrown only when somebody has deliberately created a `hard` budget row. Refusing silently
 * is forbidden: whoever created the cap gets a notification saying what did not run.
 */
final class BudgetExceeded extends RuntimeException {}
