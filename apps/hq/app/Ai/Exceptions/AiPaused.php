<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * Pause All AI is on, or its state could not be read, so nothing was asked of a model and no tool an
 * agent proposed was run (docs/138 ruling 7, docs/141).
 *
 * Not a provider error: the circuit breaker is not touched and no fallback provider is tried, because
 * every provider is paused alike.
 */
final class AiPaused extends RuntimeException {}
