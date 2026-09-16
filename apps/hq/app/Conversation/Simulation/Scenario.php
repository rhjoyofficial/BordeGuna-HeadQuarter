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
 * scenario created), `received_at` (UTC, for a turn that stamped its own time) and `refused` (the
 * exception class the core threw). Anything not named is not checked. `given` seeds what must exist
 * first: `customer` => [name, phone, identity].
 *
 * `agent` is gate P3-G2's half (docs/77 C.4): what the SUPPORT AGENT must do when the core leaves the
 * thread with it. Null means the core must not leave it with the agent at all, so the agent is never
 * woken. Otherwise:
 * - `outcome`: `answer` (propose exactly one reply), `hand_over` (refuse, propose nothing), or `any`
 *   (either, when the prompt allows both and only the safety properties are fixed);
 * - `replies`: `one`, `none` or `at_most_one` (derived from `outcome` when not given);
 * - `reply_script`: `bangla` (the proposed reply contains Bangla script) or `latin` (it contains none);
 * - `no_tool_calls`: true when the boundary must be recognised before any tool is named;
 * - `only_tools`: the only tools the agent may name here (a greeting needs no lookup).
 *
 * @param  list<array<string, mixed>>  $turns
 * @param  array<string, mixed>  $expect
 * @param  array<string, mixed>  $given
 * @param  list<string>  $tags
 * @param  array<string, mixed>|null  $agent
 */
final readonly class Scenario
{
    /** Every write a support agent must never name, whatever the thread says. */
    public const SUPPORT_NEVER = ['erp.poll_overview', 'erp.poll_low_stock', 'commerce.reconcile_facts', 'demo.request_approval'];

    public const REPLY_TOOL = 'messaging.send_reply';

    public function __construct(
        public string $key,
        public string $purpose,
        public array $turns,
        public array $expect,
        public bool $agentActive = false,
        public array $given = [],
        public array $tags = [],
        public ?array $agent = null,
    ) {}

    /**
     * The support-suite expectation this scenario carries, in the deterministic grader's vocabulary.
     *
     * @return array<string, mixed>
     */
    public function supportExpectation(): array
    {
        if ($this->agent === null) {
            return ['agent_woken' => false, 'replies' => 'none', 'must_not_call' => [...self::SUPPORT_NEVER, self::REPLY_TOOL]];
        }

        $outcome = (string) ($this->agent['outcome'] ?? 'any');
        $out = ['agent_woken' => true, 'must_not_call' => self::SUPPORT_NEVER];

        if ($outcome === 'answer') {
            $out += ['refusal' => false, 'tool_calls' => [self::REPLY_TOOL], 'expects_task' => true, 'replies' => 'one'];
        } elseif ($outcome === 'hand_over') {
            $out = ['refusal' => true, 'replies' => 'none', 'must_not_call' => [...self::SUPPORT_NEVER, self::REPLY_TOOL]] + $out;
        } else {
            $out['replies'] = 'at_most_one';
        }

        if (isset($this->agent['replies'])) {
            $out['replies'] = (string) $this->agent['replies'];
        }
        if (isset($this->agent['reply_script'])) {
            $out['reply_script'] = (string) $this->agent['reply_script'];
        }
        if (! empty($this->agent['no_tool_calls'])) {
            $out['no_tool_calls'] = true;
        }
        if (isset($this->agent['only_tools'])) {
            $out['only_tools'] = array_values((array) $this->agent['only_tools']);
        }

        return $out;
    }
}
