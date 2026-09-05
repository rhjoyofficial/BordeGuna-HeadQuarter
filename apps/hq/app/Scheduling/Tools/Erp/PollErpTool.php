<?php

namespace App\Scheduling\Tools\Erp;

use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Erp\ErpConnector;
use App\Erp\ErpRegistry;
use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Scheduling\Tools\Tool;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolResult;

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
            ['payload' => $result['payload'], 'as_of' => $result['as_of'], 'polled_at' => now(), 'job_run_ref' => $context->jobRunId ? (string) $context->jobRunId : null],
        );

        return ToolResult::succeeded([
            'business' => $business->slug,
            'kind' => $this->kind(),
            'as_of' => $result['as_of'],
            'items' => is_array($result['payload']) && array_is_list($result['payload']) ? count($result['payload']) : null,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // a poll is safe to repeat
    }
}
