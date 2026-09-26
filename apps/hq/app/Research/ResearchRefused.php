<?php

namespace App\Research;

use RuntimeException;

/**
 * A research or content step refused, with the reason a person reads (docs/85 C.19: a failure goes to
 * a person, never smoothed over). Tools turn it into a failed result; nothing retries it silently.
 */
final class ResearchRefused extends RuntimeException {}
