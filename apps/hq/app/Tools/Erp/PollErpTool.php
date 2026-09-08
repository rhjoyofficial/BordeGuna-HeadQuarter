<?php

namespace App\Tools\Erp;

use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Erp\ErpConnector;
use App\Erp\ErpRegistry;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Shared shape for the ERP poll tools: resolve the business, call the connector, replace the
 * snapshot row for (business, kind). Idempotent by construction: polling twice writes the same row
 * twice, so reconcile() has nothing to look up and a retry simply polls again.
 *
 * A business named in the payload beats the context's business, so one scheduled job can poll a
 * business other than the one it is filed under; with neither, the tool refuses rather than guessing.
 */
abstract class PollErpTool implements Tool
{
    public function __construct(protected readonly ErpRegistry $registry) {}

    abstract protected function kind(): string;

    /** @return array{payload: array, as_of: ?string} */
    abstract protected function poll(ErpConnector $erp): array;

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['business_slug' => ['type' => 'string', 'maxLength' => 64]],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'as_of' => ['type' => 'string'],
            'items' => ['type' => 'integer'],
        ]];
    }

    /**
     * Shared descriptor for both poll tools. They differ only in key, title and purpose.
     *
     * Tier R3, not R2. The rule is the highest tier of any effect the tool has: it reads the ERP
     * (R2) and then writes a facts_erp_snapshots row (R3, HQ state only, reversible, invisible
     * outside HQ). Classifying it R2 because "it is a poll" would be describing the intention
     * rather than the effect.
     *
     * Credential: the ERP token is per business, so account_ref follows whichever business the call
     * actually resolves to - the argument first, then the one the job is filed under, which is the
     * same order execute() uses.
     */
    protected function baseDescriptor(string $title, string $purpose): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: $title,
            purpose: $purpose,
            riskTier: RiskTier::R3,
            permission: 'hq.tools.erp.read',
            auditAction: 'tool.'.str_replace('.', '_', $this->key()),
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            // The job rows created in Phase 1 name their business in the PAYLOAD and leave the
            // request context null. Declaring it here is what lets the gateway resolve the target,
            // enforce business scope on it, and record it on the tool_calls row.
            scopeFrom: 'business:args.business_slug',
            credentials: [new CredentialRequirement(ErpRegistry::PROVIDER, 'args.business_slug|business.slug')],
            idempotent: true,
            idempotencyKeyFrom: ['business_slug'],
            timeoutSeconds: 30,
            outputSchema: $this->outputSchema(),
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $business = isset($payload['business_slug'])
            ? Business::where('slug', $payload['business_slug'])->first()
            : ($context->businessId ? Business::find($context->businessId) : null);

        if (! $business) {
            return ToolResult::failed('No business given: pass business_slug or schedule the job under a business.');
        }

        try {
            $erp = $this->registry->for($business);
            $result = $this->poll($erp);
        } catch (Unsupported $e) {
            return ToolResult::failed($e->getMessage()); // configuration problem; retrying will not help
        } catch (Unavailable $e) {
            throw $e; // let the scheduler retry with backoff
        }

        FactsErpSnapshot::updateOrCreate(
            ['business_id' => $business->id, 'kind' => $this->kind()],
            ['payload' => $result['payload'], 'as_of' => self::asOf($result['as_of']), 'polled_at' => now(), 'job_run_ref' => $context->jobRunId ? (string) $context->jobRunId : null],
        );

        return ToolResult::succeeded([
            'business' => $business->slug,
            'kind' => $this->kind(),
            'as_of' => $result['as_of'],
            'items' => is_array($result['payload']) && array_is_list($result['payload']) ? count($result['payload']) : null,
        ]);
    }

    /**
     * The ERP timestamps its responses in its own timezone: `2026-09-07T11:41:09+06:00`.
     *
     * Handing that string straight to a datetime cast stored the wall-clock reading and dropped the
     * offset, so every snapshot claimed to be six hours newer than the moment it described - the
     * 6 September overview was written at 07:39 UTC and recorded `as_of` 13:39 UTC, a timestamp in
     * its own future. Nothing complained, because a timestamp is valid whatever it says.
     *
     * Parsing to an instant and storing UTC makes the column mean one thing regardless of which
     * timezone the source happens to run in.
     */
    private static function asOf(?string $raw): ?CarbonImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (\Throwable) {
            return null; // an unparseable timestamp is better recorded as unknown than as a wrong instant
        }
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // a poll is safe to repeat
    }
}
