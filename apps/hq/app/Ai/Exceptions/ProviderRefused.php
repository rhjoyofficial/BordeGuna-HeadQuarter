<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider rejected the request itself: a malformed body, an unknown model, a bad key.
 *
 * ⚠ This is NOT retryable and must NOT trip the circuit breaker. Sending the same broken request to
 * a second provider only produces a second refusal, and counting it as an outage would take a
 * healthy provider out of rotation because of a bug in HQ.
 */
final class ProviderRefused extends RuntimeException {}
