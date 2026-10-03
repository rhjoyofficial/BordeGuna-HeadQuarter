<?php

namespace App\Tools\Erp;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpPlatform;
use App\Erp\ErpRegistry;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * What the five Phase 4 read tools share (docs/81 C.5.3, docs/79).
 *
 * ⛔ **Reads only, and the posture is fixed here, not per tool.** Every order-read tool is R2,
 * read-only, business-scoped, audited under `hq.tools.erp.read`, idempotent with a 60-second replay,
 * and names its business in a REQUIRED `business_slug`. A subclass states what it asks and what it
 * returns; it cannot state a different tier, a write, or another scope. The future order write is
 * a separate capability with its own gates (`docs/79`), and `take_order` is registered nowhere.
 *
 * ⚠ **No agent holds any of these tools.** `agentExecutable` says whether an agent COULD be given
 * one; giving it is a change to an agent's tool list, which is its own reviewed decision and is not
 * made here. The agents' lists and stamps are untouched by the tools' existence.
 *
 * ⚠ **Every answer carries its provenance**: the business, `source: erp`, the contract version, the
 * ERP's own `as_of`, and when HQ read it. "Not found" and "no price in force" come back as facts
 * with the ERP's own sentence, never as an error and never as a zero.
 */
abstract class ErpOrderReadTool implements Tool
{
    public function __construct(protected readonly ErpRegistry $registry) {}

    abstract protected function title(): string;

    abstract protected function purpose(): string;

    /** @return array<string, array<string, mixed>> the tool's own arguments, besides business_slug */
    abstract protected function arguments(): array;

    /** @return list<string> the tool's own required arguments, besides business_slug */
    protected function requiredArguments(): array
    {
        return [];
    }

    /** @return array<string, mixed> the output properties, besides the provenance every tool carries */
    abstract protected function outputProperties(): array;

    /**
     * Ask the ERP, and turn its answer into this tool's output (without the provenance).
     *
     * @return array{0: ErpOrderRead, 1: array<string, mixed>}|ToolResult
     */
    abstract protected function read(ErpPlatform $erp, array $payload): array|ToolResult;

    protected function agentExecutable(): bool
    {
        return true;
    }

    /** The ERP contract this tool's answers speak; the order-reads' unless a subclass reads another. */
    protected function contract(): string
    {
        return ErpPlatform::ORDER_READS_CONTRACT;
    }

    protected function dataClass(): DataClass
    {
        return DataClass::Internal;
    }

    /** @return list<string> */
    protected function redact(): array
    {
        return [];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: $this->title(),
            purpose: $this->purpose(),
            riskTier: RiskTier::R2,
            permission: 'hq.tools.erp.read',
            auditAction: 'tool.'.str_replace('.', '_', $this->key()),
            scope: ToolScope::Business,
            readWrite: ReadWrite::Read,
            agentExecutable: $this->agentExecutable(),
            scopeFrom: 'business:args.business_slug',
            cardinality: ToolCardinality::OnePerBusiness,
            credentials: [
                new CredentialRequirement(ErpRegistry::PROVIDER, 'args.business_slug|business.slug'),
            ],
            idempotent: true,
            idempotencyKeyFrom: array_merge(['business_slug'], array_keys($this->arguments())),
            timeoutSeconds: 30,
            redact: $this->redact(),
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: 60,
            dataClass: $this->dataClass(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['business_slug' => ['type' => 'string', 'maxLength' => 64]] + $this->arguments(),
            'required' => array_merge(['business_slug'], $this->requiredArguments()),
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'contract' => ['type' => 'string'],
            'as_of' => ['type' => 'string'],
            'read_live_at' => ['type' => 'string'],
        ] + $this->outputProperties()];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        // The business the gateway resolved and checked scope against; the slug only as a fallback,
        // exactly as erp.run_report reads it.
        $businessId = $context->businessId;
        if ($businessId === null && ($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
        }
        $business = $businessId === null ? null : Business::find($businessId);
        if ($business === null) {
            return ToolResult::failed('Name the business to read from the ERP.');
        }

        try {
            $out = $this->read($this->registry->for($business), $payload);
        } catch (Unsupported|Refused|ValidationFailed $e) {
            // A token without the ability, a contract that changed, a request the ERP refused: an
            // answer to report, not a fault to retry.
            return ToolResult::failedBy($e);
        } catch (Unavailable $e) {
            throw $e;
        }

        if ($out instanceof ToolResult) {
            return $out;
        }
        [$read, $output] = $out;

        // docs/90 A.5: a read that concerns an order HQ placed records what the ERP said as a fact
        // the moment it was read (the status tool overrides this; every other read keeps nothing).
        $this->afterRead($business, $read, $context);

        return ToolResult::succeeded($this->provenance($business, $read) + $output);
    }

    /** What to keep from a successful read, if anything. Nothing, unless a tool says otherwise. */
    protected function afterRead(Business $business, ErpOrderRead $read, ToolContext $context): void {}

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /** @return array<string, mixed> */
    private function provenance(Business $business, ErpOrderRead $read): array
    {
        return [
            'business' => $business->slug,
            'source' => 'erp',
            'contract' => $this->contract(),
            'as_of' => $read->asOf(),
            'read_live_at' => CarbonImmutable::now((string) config('hq.timezone', 'Asia/Dhaka'))->toIso8601String(),
        ];
    }
}
