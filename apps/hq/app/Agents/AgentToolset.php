<?php

namespace App\Agents;

use App\Execution\EffectiveExecutionContext;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Scope\BusinessReferenceResolver;
use App\Gateway\Scope\ScopeResolver;
use App\Models\Agent;
use App\Tools\ToolRegistry;

/**
 * What an agent may actually name in a turn, and how that list is described to it.
 *
 * ## Five narrowings, and every one of them can only remove
 *
 *   1. `config('hq.tools')` - the tool exists in the deployed code at all.
 *   2. `tool_registrations` - it is switched on at runtime for this business.
 *   3. `agents.allowed_tools` - this agent was granted it (an empty list grants nothing).
 *   4. `descriptor.agentExecutable` - an agent may run it, rather than only propose it.
 *   5. the run's Effective Execution Context - BOTH the person who asked and the enabling human
 *      hold the tool's permission (ADR-047). The list is built from the same object the gateway
 *      checks each call against, so the agent is never shown a tool this run cannot use.
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
        private ScopeResolver $scopes,
        private BusinessReferenceResolver $references,
    ) {}

    /**
     * The tool keys this agent may name.
     *
     * @return list<string>
     */
    public function keysFor(EffectiveExecutionContext $context): array
    {
        $allowed = (array) ($context->agent->allowed_tools ?? []);
        $businessId = $context->businessId;
        $out = [];

        foreach ($this->registry->keys() as $key) {
            if (! in_array($key, $allowed, true) || ! $context->permitsTool($key)) {
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
     * ## The scope argument is the agent's contract, not a hint (docs/67, ADR-039)
     *
     * ⭐ A tool scoped to a business resolves that business from an argument it declares in
     * `scopeFrom`. In the tool's own schema that argument is optional, because a scheduled job or
     * a screen names the business in the request context instead. An agent has no such context
     * unless the run was opened for one business, and gate 4 showed what an optional argument
     * means to a model: it was left out, refused, and asked for again identically. So the catalogue
     * an agent reads is stricter than the tool's schema: the scope argument is `required`, and its
     * `enum` is exactly the businesses (or stores) this agent may name in this run. The gateway
     * still validates against the tool's schema and still resolves and checks scope; nothing here
     * widens what runs. It only stops the model guessing.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(EffectiveExecutionContext $context): array
    {
        $out = [];

        foreach ($this->keysFor($context) as $key) {
            $tool = $this->registry->get($key);
            $descriptor = $tool->descriptor();

            $entry = [
                'tool' => $key,
                'purpose' => $descriptor->purpose,
                'arguments' => $this->agentSchema($context, $key, $descriptor, $tool->inputSchema()),
            ];

            // ⭐ How many calls a question is (ADR-040). A tool that returns one business says so in
            // a sentence beside its arguments, so a group-wide question is read as one call per
            // business rather than one call with no business.
            if ($descriptor->cardinality !== ToolCardinality::HqWide) {
                $entry['scope_semantics'] = $descriptor->cardinality->semantics();
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The tool's schema with its scope argument made required and enumerated.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function agentSchema(EffectiveExecutionContext $context, string $key, ToolDescriptor $descriptor, array $schema): array
    {
        foreach ($this->scopes->declaredPaths($descriptor) as $kind => $argument) {
            if (! isset($schema['properties'][$argument]) || ! is_array($schema['properties'][$argument])) {
                continue;
            }

            $values = $kind === 'business'
                ? $this->references->permittedBusinessSlugs($context, $key)
                : $this->references->permittedStoreSlugs($context, $key);

            // An empty enum is a schema no value satisfies. If there is nothing to name, the tool
            // stays listed with its argument required, and the gateway will say so if it is called.
            // ⭐ `all` is the one group reference (ADR-041): HQ expands it into one call per permitted
            // target itself, so a group-wide question never has to be one call with no business.
            if ($values !== []) {
                $schema['properties'][$argument]['enum'] = $descriptor->cardinality === ToolCardinality::HqWide
                    ? $values
                    : [...$values, AgentRunner::GROUP_REFERENCE];
            }

            $required = array_values(array_filter((array) ($schema['required'] ?? []), 'is_string'));
            if (! in_array($argument, $required, true)) {
                $required[] = $argument;
            }
            $schema['required'] = $required;
        }

        return $schema;
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
