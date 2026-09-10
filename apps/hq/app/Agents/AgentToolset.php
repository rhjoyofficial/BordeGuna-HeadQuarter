<?php

namespace App\Agents;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Registry\ToolAvailability;
use App\Models\Agent;
use App\Tools\ToolRegistry;

/**
 * What an agent may actually name in a turn, and how that list is described to it.
 *
 * ## Four narrowings, and every one of them can only remove
 *
 *   1. `config('hq.tools')` - the tool exists in the deployed code at all.
 *   2. `tool_registrations` - it is switched on at runtime for this business.
 *   3. `agents.allowed_tools` - this agent was granted it (an empty list grants nothing).
 *   4. `descriptor.agentExecutable` - an agent may run it, rather than only propose it.
 *
 * ⛔ **This is a convenience, not the control.** Every proposal still goes through the whole gateway
 * sequence, where the permission intersection, policy, consent, credentials and approval are decided
 * again with no reference to this list. Narrowing here means a model wastes fewer turns naming
 * things it cannot have; it is not what stops it having them. A test asserts the gateway refuses a
 * tool that is missing from an agent's list even when the runner is asked to call it directly.
 *
 * ⚠ The catalogue given to the model contains no risk tiers, no permission names and no credential
 * requirements. A model does not need to know which permission guards a tool, and describing the
 * control plane to the thing being controlled is free reconnaissance for anything that later reads
 * an injected instruction.
 */
final readonly class AgentToolset
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolAvailability $availability,
    ) {}

    /**
     * The tool keys this agent may name.
     *
     * @return list<string>
     */
    public function keysFor(Agent $agent, ?int $businessId = null): array
    {
        $allowed = (array) ($agent->allowed_tools ?? []);
        $out = [];

        foreach ($this->registry->keys() as $key) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            $descriptor = $this->descriptor($key);
            if ($descriptor === null || ! $descriptor->agentExecutable) {
                continue;
            }

            if (! ($this->availability->check($key, $businessId)['available'] ?? false)) {
                continue;
            }

            $out[] = $key;
        }

        return $out;
    }

    /**
     * The catalogue, as it is written into the prompt.
     *
     * Each entry is the key, what it is for, and its input schema. The schema is included because a
     * model that cannot see the accepted arguments guesses them, and a guessed argument is a
     * validation refusal that costs a turn out of three.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Agent $agent, ?int $businessId = null): array
    {
        $out = [];

        foreach ($this->keysFor($agent, $businessId) as $key) {
            $tool = $this->registry->get($key);
            $descriptor = $tool->descriptor();

            $out[] = [
                'tool' => $key,
                'purpose' => $descriptor->purpose,
                'arguments' => $tool->inputSchema(),
            ];
        }

        return $out;
    }

    /**
     * Why this agent may not name a tool, or null when it may.
     *
     * Used by the runner to answer a bad proposal with a reason rather than a silence. A model told
     * "that tool is not on your list" stops asking; a model told nothing asks again in different
     * words, which is the loop the run bounds exist to stop.
     */
    public function refusalFor(Agent $agent, string $key, ?int $businessId = null): ?string
    {
        if (! $this->registry->has($key)) {
            return "There is no tool called [{$key}]. Only name tools from the list you were given.";
        }

        $descriptor = $this->descriptor($key);

        if ($descriptor !== null && ($reason = $descriptor->agentRefusalReason()) !== null) {
            return $reason;
        }

        if (! in_array($key, (array) ($agent->allowed_tools ?? []), true)) {
            return "[{$key}] is not granted to this agent. A human can run it; you may only mention that it exists.";
        }

        $availability = $this->availability->check($key, $businessId);
        if (! ($availability['available'] ?? false)) {
            return (string) ($availability['reason'] ?? "[{$key}] is switched off.");
        }

        return null;
    }

    private function descriptor(string $key): ?ToolDescriptor
    {
        if (! $this->registry->has($key)) {
            return null;
        }

        try {
            return $this->registry->get($key)->descriptor();
        } catch (\Throwable) {
            return null;
        }
    }
}
