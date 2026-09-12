<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider stopped the reply at the output limit, and the retry policy had nothing left to try.
 *
 * ⚠ This is NOT a `StructuredOutputFailed`. A reply cut off mid-object is not a malformed object,
 * it is an unfinished one, and the two need different responses: a malformed reply is re-asked with
 * the validation error; a truncated one is re-asked with more room, once, and then reported as what
 * it is. The third safety gate failed a case because the two were conflated (docs/65, ADR-038).
 */
final class OutputTruncated extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $outputTokens,
        public readonly int $budget,
    ) {
        parent::__construct($message);
    }
}
