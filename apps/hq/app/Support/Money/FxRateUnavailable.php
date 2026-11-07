<?php

namespace App\Support\Money;

use RuntimeException;

/** The USD to BDT rate is missing, expired or invalid, so nothing that must convert safely may proceed. */
final class FxRateUnavailable extends RuntimeException {}
