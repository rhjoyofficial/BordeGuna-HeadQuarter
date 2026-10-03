<?php

namespace App\Conversation\Simulation;

use App\Conversation\SupportWorkflow;

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
 * - `reply_script`: `bangla` (the accepted reply's prose is in Bengali script) or `latin` (in Latin script),
 *   by the runtime's own `ScriptPolicyValidator` (docs/92 A.7: identifiers and brand names are not prose);
 * - `language_requirement`: `{language, script}` the RUNTIME must resolve for the thread from the active
 *   language policy before the model is asked (docs/92 A.7), and must not end by refusing the reply for;
 * - `no_tool_calls`: true when the boundary must be recognised before any tool is named;
 * - `only_tools`: the only tools the agent may name here (a greeting needs no lookup).
 *
 * @param  list<array<string, mixed>>  $turns
 * @param  array<string, mixed>  $expect
 * @param  array<string, mixed>  $given
 * @param  list<string>  $tags
 * @param  array<string, mixed>|null  $agent
 *
 * `workflow` (docs/90 A.1 step 5) is the contract the thread declares for the support agent's run:
 * `silent_handover`, `greeting_first` or `context_first` (`SupportWorkflow`); null leaves it to the
 * runtime's own reading. `version` is bumped when a scenario's agent contract changes, so the case
 * seeded from it gets a new key and the old case is retired with its results, never edited in place
 * (docs/88 A point 15).
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
        public ?string $workflow = null,
        public int $version = 1,
    ) {}

    /** The evaluation case key seeded from this scenario: `sim.<key>` at version 1, `sim.v<n>.<key>` after. */
    public function caseKey(): string
    {
        return 'sim.'.($this->version > 1 ? "v{$this->version}." : '').$this->key;
    }

    /** The case key this scenario carried at version 1, which its retirement row keeps. */
    public function legacyCaseKey(): string
    {
        return 'sim.'.$this->key;
    }

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
        if (is_array($this->agent['language_requirement'] ?? null)) {
            $out['language_requirement'] = ['language' => (string) $this->agent['language_requirement']['language'], 'script' => (string) $this->agent['language_requirement']['script']];
        }
        if (! empty($this->agent['no_tool_calls'])) {
            $out['no_tool_calls'] = true;
        }
        if (isset($this->agent['only_tools'])) {
            $out['only_tools'] = array_values((array) $this->agent['only_tools']);
        }

        // docs/90 A.1 step 5: the declared workflow, and what it fixes. A silent hand-over is a hand-over
        // with no reply and no tool named; greeting first allows the reply tool alone.
        if ($this->workflow === SupportWorkflow::SILENT_HANDOVER) {
            $out = ['refusal' => true, 'replies' => 'none', 'no_tool_calls' => true,
                'must_not_call' => array_values(array_unique([...self::SUPPORT_NEVER, self::REPLY_TOOL])), 'support_workflow' => $this->workflow] + $out;
        } elseif ($this->workflow === SupportWorkflow::GREETING_FIRST) {
            $out['only_tools'] = [self::REPLY_TOOL];
            $out['support_workflow'] = $this->workflow;
        } elseif ($this->workflow === SupportWorkflow::CONTEXT_FIRST) {
            $out['support_workflow'] = $this->workflow;
        }

        return $out;
    }

    /**
     * The expectation the scenario carried before its workflow was declared (version 1), which the
     * retired case keeps exactly as attempt 1 graded it.
     *
     * @return array<string, mixed>
     */
    public function legacySupportExpectation(): array
    {
        $out = (new self($this->key, $this->purpose, $this->turns, $this->expect, $this->agentActive, $this->given, $this->tags, $this->agent))->supportExpectation();
        // Neither the workflow contract (docs/90) nor the runtime language requirement (docs/92) existed at version 1.
        unset($out['support_workflow'], $out['language_requirement']);

        return $out;
    }
}
