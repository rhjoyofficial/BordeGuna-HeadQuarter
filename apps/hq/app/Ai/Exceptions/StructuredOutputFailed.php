<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The model was asked for an object matching a schema and did not produce one, twice.
 *
 * ⚠ HQ never "fixes up" a malformed structured reply by guessing at the missing fields. One repair
 * attempt is made, with the validation errors handed back to the model; after that the call fails.
 * A silently patched object is a fabricated one, and it would flow onward as though it were real.
 */
final class StructuredOutputFailed extends RuntimeException {}
