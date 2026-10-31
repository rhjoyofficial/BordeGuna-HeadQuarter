<?php

namespace App\Tools\Connectors;

use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\OutcomeClass;
use App\Models\Business;
use App\Support\Features\Features;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * What the HQ-completion connector tools share (docs/144-*): each is a gateway tool with a permission, an audit
 * action, a feature flag that ships OFF, and credentials declared as OPTIONAL (the connector the registry picks
 * decides which one it needs; a missing one is a typed "not connected yet", never a crash).
 *
 * ⛔ A tool refuses, before doing anything, when its flag is off, when the business is missing, or when the
 * person is limited to businesses that do not include it. None is agent-executable: no agent holds any of them
 * (the agent seeder is untouched), so each is reachable only by a person, a job a person approved, or the console.
 */
abstract class ConnectorTool implements Tool
{
    abstract protected function title(): string;

    abstract protected function purpose(): string;

    abstract protected function flag(): string;

    abstract protected function permission(): string;

    /** @return array<string, string> credential kind => account reference source (`=hq`, `args.business_slug|business.slug`, ...) */
    abstract protected function credentialSources(): array;

    /** @return array<string, array<string, mixed>> the tool's own arguments, besides business_slug */
    abstract protected function arguments(): array;

    /** @return list<string> required arguments besides business_slug */
    protected function required(): array
    {
        return [];
    }

    abstract protected function run(?Business $business, array $payload, ToolContext $context): ToolResult;

    protected function businessScoped(): bool
    {
        return true;
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R3;
    }

    protected function readWrite(): ReadWrite
    {
        return ReadWrite::Write;
    }

    protected function idempotent(): bool
    {
        return false;
    }

    /** @return list<string> */
    protected function idempotencyKeyFrom(): array
    {
        return [];
    }

    protected function requesterMayDecide(): bool
    {
        return true;
    }

    protected function dataClass(): DataClass
    {
        return DataClass::Internal;
    }

    protected function timeoutSeconds(): int
    {
        return 60;
    }

    public function descriptor(): ToolDescriptor
    {
        $credentials = [];
        foreach ($this->credentialSources() as $kind => $source) {
            $credentials[] = new CredentialRequirement($kind, $source, optional: true);
        }

        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: $this->title(),
            purpose: $this->purpose(),
            riskTier: $this->riskTier(),
            permission: $this->permission(),
            auditAction: 'tool.'.str_replace('.', '_', $this->key()),
            scope: $this->businessScoped() ? ToolScope::Business : ToolScope::Hq,
            readWrite: $this->readWrite(),
            agentExecutable: false,
            scopeFrom: $this->businessScoped() ? 'business:args.business_slug' : null,
            credentials: $credentials,
            idempotent: $this->idempotent(),
            idempotencyKeyFrom: $this->idempotencyKeyFrom(),
            timeoutSeconds: $this->timeoutSeconds(),
            outputSchema: $this->outputSchema(),
            dataClass: $this->dataClass(),
            cardinality: $this->businessScoped() ? ToolCardinality::OnePerBusiness : ToolCardinality::HqWide,
            requesterMayDecide: $this->requesterMayDecide(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ($this->businessScoped() ? ['business_slug' => ['type' => 'string', 'maxLength' => 64]] : []) + $this->arguments(),
            'required' => [...($this->businessScoped() ? ['business_slug'] : []), ...$this->required()],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['connector_state' => ['type' => 'string']]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $business = null;
        if ($this->businessScoped()) {
            $businessId = $context->businessId ?? Business::query()->where('slug', $payload['business_slug'] ?? null)->value('id');
            $business = $businessId === null ? null : Business::find($businessId);
            if ($business === null) {
                return ToolResult::failed('Name the business this is for.', [], OutcomeClass::InvalidArguments);
            }
            // Fail closed: a person limited to other businesses never reaches this business, whatever the gateway did.
            if ($context->permittedBusinessIds !== null && ! in_array((int) $business->id, $context->permittedBusinessIds, true)) {
                return ToolResult::failed('That business is outside what you may use.', [], OutcomeClass::ScopeDenied);
            }
        }

        if (! app(Features::class)->enabled($this->flag(), $business?->id)) {
            return ToolResult::failed('Not connected yet: the switch ['.$this->flag().'] is off.', ['connector_state' => 'disabled'], OutcomeClass::FeatureDisabled);
        }

        return $this->run($business, $payload, $context);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
