<?php

namespace App\Scheduling\Nl;

/**
 * What the validator decided: a job it can stand behind, with the attributes `ScheduledJobService`
 * takes and a preview in words, or the questions a person must answer first. Never both.
 */
final readonly class JobSpecResult
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $preview
     * @param  list<string>  $questions
     */
    private function __construct(
        public bool $ok,
        public array $attributes,
        public array $preview,
        public array $questions,
    ) {}

    public static function job(array $attributes, array $preview): self
    {
        return new self(true, $attributes, $preview, []);
    }

    /** @param list<string> $questions */
    public static function questions(array $questions): self
    {
        return new self(false, [], [], array_values(array_unique(array_filter($questions))));
    }

    public function toArray(): array
    {
        return ['ok' => $this->ok, 'attributes' => $this->attributes, 'preview' => $this->preview, 'questions' => $this->questions];
    }
}
