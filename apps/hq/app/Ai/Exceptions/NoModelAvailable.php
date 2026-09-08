<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * No row in `model_tiers` can serve this request: none active at the tier, none meeting the required
 * capabilities or context size, or every candidate's provider is unconfigured or circuit-broken.
 *
 * ⚠ Refusing loudly is deliberate. The alternative, quietly running on some other model, is exactly
 * the outcome the router exists to prevent.
 */
final class NoModelAvailable extends RuntimeException {}
