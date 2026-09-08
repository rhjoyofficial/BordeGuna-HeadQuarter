<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider could not answer: a timeout, a transport failure, a 5xx, or a rate limit.
 *
 * ⚠ This is the RETRYABLE class. It trips the circuit breaker and triggers fallback, because the
 * request itself was fine and somebody else may be able to serve it.
 */
final class ProviderUnavailable extends RuntimeException {}
