<?php

namespace App\Ai\Providers;

use App\Models\AiModel;

/**
 * One call to one model, in provider-neutral terms.
 *
 * ⚠ The model is carried as an `AiModel` ROW, not a string. A driver reads the id off the row rather
 * than being handed a bare string, which is what keeps model ids out of the calling code entirely
 * (ADR-019) and guarantees the same row that selected the model also prices it.
 */
final readonly class ModelRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  when set, the reply must be an object matching it
     */
    public function __construct(
        public AiModel $model,
        public string $system,
        public array $messages,
        public int $maxOutputTokens = 1024,
        public ?array $jsonSchema = null,
        public ?int $timeoutSeconds = null,
        /**
         * Marks the leading, stable part of the prompt as cacheable. HQ's system prompts and its
         * business context are the same on every call in a run, and paying full input price for
         * them repeatedly is the single largest avoidable cost in this design.
         */
        public bool $cacheSystem = true,
    ) {}

    public function withSchema(?array $schema): self
    {
        return new self(
            $this->model, $this->system, $this->messages, $this->maxOutputTokens,
            $schema, $this->timeoutSeconds, $this->cacheSystem,
        );
    }

    /** @param list<array{role: string, content: string}> $messages */
    public function withMessages(array $messages): self
    {
        return new self(
            $this->model, $this->system, $messages, $this->maxOutputTokens,
            $this->jsonSchema, $this->timeoutSeconds, $this->cacheSystem,
        );
    }

    /** The same request with more room to answer: the truncation retry (ADR-038). */
    public function withMaxOutputTokens(int $maxOutputTokens): self
    {
        return new self(
            $this->model, $this->system, $this->messages, $maxOutputTokens,
            $this->jsonSchema, $this->timeoutSeconds, $this->cacheSystem,
        );
    }

    public function onModel(AiModel $model): self
    {
        return new self(
            $model, $this->system, $this->messages, min($this->maxOutputTokens, $model->max_output_tokens),
            $this->jsonSchema, $this->timeoutSeconds, $this->cacheSystem,
        );
    }
}
