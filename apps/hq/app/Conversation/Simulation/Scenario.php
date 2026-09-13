<?php

namespace App\Conversation\Simulation;

/**
 * One scripted conversation and what the core must have done with it (docs/72 addendum, the
 * simulation world).
 *
 * A turn is either a customer message, `['from' => 'psid', 'text' => '...', 'mid' => ..., 'at' => ...,
 * 'page' => ..., 'attachments' => [...]]`, or an operator step, `['action' => 'take_over' | 'resolve'
 * | 'agent_waiting' | 'close']`, so a scenario can carry a thread through a person's hands and back.
 *
 * `expect` names what is checked afterwards: `state`, `reason` (of the last transition), `language`,
 * `stranger`, `provenance` (of the last message), `threads`, `customers` and `messages` (rows the
 * scenario created), and `refused` (the exception class the core threw). Anything not named is not
 * checked. `given` seeds what must exist first: `customer` => [name, phone, identity].
 *
 * @param  list<array<string, mixed>>  $turns
 * @param  array<string, mixed>  $expect
 * @param  array<string, mixed>  $given
 * @param  list<string>  $tags
 */
final readonly class Scenario
{
    public function __construct(
        public string $key,
        public string $purpose,
        public array $turns,
        public array $expect,
        public bool $agentActive = false,
        public array $given = [],
        public array $tags = [],
    ) {}
}
