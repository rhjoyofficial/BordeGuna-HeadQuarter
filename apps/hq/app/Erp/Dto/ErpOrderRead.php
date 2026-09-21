<?php

namespace App\Erp\Dto;

/**
 * One answer from the ERP's order-reads (docs/81 C.5): either the envelope, or the ERP saying the
 * thing asked about is not there.
 *
 * ⚠ "Not found" is an ANSWER, not a failure. "No product has that SKU" and "no price is in force"
 * are facts the ERP states with a 404 and a code, and they are the opposite of a broken
 * integration; reading them as errors would hide them, and reading them as an empty success would
 * turn "no price" into a zero. So they arrive here as `found: false` with the ERP's own code and
 * sentence, and every other failure keeps its exception.
 */
final readonly class ErpOrderRead
{
    /**
     * @param  array<mixed>  $data  the envelope's `data`, or [] when not found
     * @param  array<string, mixed>  $meta  the envelope's `meta`, or [] when not found
     * @param  array<string, mixed>  $links  pagination links, when the endpoint pages
     */
    public function __construct(
        public bool $found,
        public array $data = [],
        public array $meta = [],
        public array $links = [],
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public static function notFound(string $code, string $message): self
    {
        return new self(found: false, code: $code, message: $message);
    }

    /** The ERP's own clock for this answer, never HQ's. */
    public function asOf(): ?string
    {
        $asOf = $this->meta['as_of'] ?? null;

        return is_string($asOf) ? $asOf : null;
    }
}
