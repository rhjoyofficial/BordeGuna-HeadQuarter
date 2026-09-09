<?php

namespace App\Context;

use Illuminate\Support\Str;

/**
 * One labelled piece of context, with its provenance attached to the content rather than described
 * near it (ADR-024).
 *
 * The `source` is deliberately a required, structured string ("conversation:412/message:9",
 * "erp:bor-de-guna/overview", "memory:semantic:88") rather than free prose, because the question an
 * audit asks months later is "where exactly did this sentence come from", and prose does not answer
 * it.
 */
final readonly class ContextBlock
{
    public function __construct(
        public Provenance $provenance,
        public string $source,
        public string $content,
        public ?string $id = null,
        public array $metadata = [],
    ) {}

    public static function system(string $source, string $content): self
    {
        return new self(Provenance::System, $source, $content);
    }

    public static function business(string $source, string $content, array $metadata = []): self
    {
        return new self(Provenance::Business, $source, $content, metadata: $metadata);
    }

    public static function customer(string $source, string $content, array $metadata = []): self
    {
        return new self(Provenance::Customer, $source, $content, metadata: $metadata);
    }

    public static function thirdParty(string $source, string $content, array $metadata = []): self
    {
        return new self(Provenance::ThirdParty, $source, $content, metadata: $metadata);
    }

    public static function generated(string $source, string $content, array $metadata = []): self
    {
        return new self(Provenance::Generated, $source, $content, metadata: $metadata);
    }

    public function isTrusted(): bool
    {
        return $this->provenance->isTrusted();
    }

    /** A stable fingerprint of the content, so an audit row can point at text without storing it. */
    public function digest(): string
    {
        return substr(hash('sha256', $this->content), 0, 32);
    }

    /**
     * The block as it is handed to a model, with the boundary written into the text as well.
     *
     * ⚠ The markers are BELT, not braces. They help a well-behaved model notice the boundary; they
     * do not enforce it. Enforcement is `AutonomyResolver` reading `provenance`, because the marker
     * is inside the same channel the attacker controls and could be imitated.
     */
    public function render(): string
    {
        if ($this->isTrusted()) {
            return $this->content;
        }

        $tag = strtoupper($this->provenance->value);

        return "<untrusted source=\"{$this->source}\" kind=\"{$tag}\">\n{$this->content}\n</untrusted>";
    }

    /** Safe for audit and evaluation records: provenance and a digest, never the raw text. */
    public function toAuditArray(): array
    {
        return [
            'id' => $this->id ?? Str::uuid()->toString(),
            'provenance' => $this->provenance->value,
            'source' => $this->source,
            'digest' => $this->digest(),
            'bytes' => strlen($this->content),
            'trusted' => $this->isTrusted(),
        ];
    }
}
