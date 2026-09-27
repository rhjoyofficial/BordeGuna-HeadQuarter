<?php

namespace App\Ai\ToolCalls;

/**
 * One tool call as the model proposed it, in HQ's canonical shape (docs/90 A.1 step 2).
 *
 * The provider's structured reply names a call's argument payload however the model chose to
 * (`arguments`, `args`, `input`, `parameters`); the catalogue the model reads calls it `arguments`
 * and the runner used to read `args` only, so every call a real model ever made arrived with no
 * arguments and the scope expansion handed the gateway `{business_slug}` in their place. This DTO is
 * the one representation everything downstream reads: the runner, the scope expansion, the records
 * on the run, the evaluation grader.
 *
 * ⭐ `argumentsKey` says WHICH key the payload came under, or null when the call carried none. The
 *    distinction matters: a payload that is present and empty (`{}`) on a group-wide question is a
 *    call the runtime may expand per business (docs/77); a call with no payload at all is refused
 *    by the gateway with the missing argument named, and never given a default.
 *
 * ⛔ Nothing here validates or authorises. The tool descriptor is the schema authority and the
 *    gateway's `ArgumentValidator` is the only place an argument is judged; this class only stops
 *    the model's words being lost before they get there.
 */
final readonly class ProposedToolCall
{
    /**
     * @param  array<string, mixed>  $arguments  the payload, exactly as the model wrote it
     * @param  array<string, mixed>  $raw  the whole call as the model wrote it
     */
    public function __construct(
        public string $tool,
        public array $arguments,
        public ?string $why,
        public ?string $argumentsKey,
        public array $raw,
    ) {}

    /** Whether the model wrote an argument payload at all, even an empty one. */
    public function hasArguments(): bool
    {
        return $this->argumentsKey !== null;
    }

    /**
     * The runner's internal call shape. `args` is HQ's stable name for the payload (every record on a
     * run and every grader has read it since the first gate); `arguments_key` and `raw_arguments` are
     * the evidence of what the model actually wrote, kept beside it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'args' => $this->arguments,
            'why' => $this->why,
            'arguments_key' => $this->argumentsKey,
            'raw_arguments' => $this->argumentsKey === null ? null : ($this->raw[$this->argumentsKey] ?? null),
        ];
    }
}
