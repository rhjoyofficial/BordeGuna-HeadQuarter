<?php

namespace App\Tools\Hq;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\DomainEvent;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the shops and the ERP have told HQ lately, and what never arrived (docs/48 D.6).
 *
 * ⭐ **The valuable half is the failures.** A `dead` event is a fact HQ was told and did not record,
 * and every number built on that fact is quietly wrong afterwards - which is exactly the shape of
 * the `docs/47` finding, where HQ held one order line for an order that had two, with nothing
 * anywhere reporting a problem. An agent that can see the dead letter queue can say "this figure
 * may be short" instead of stating it flatly.
 *
 * ⛔ **Payloads are never returned.** An event payload is a whole order: names, phone numbers,
 * addresses. The agent gets the type, the status, the age and the error. What HQ did with the event
 * is the question; who the customer was is not, and V.6 keeps that out of routine prompts.
 */
final class RecentEventsTool implements Tool
{
    private const MAX = 100;

    public function key(): string
    {
        return 'hq.recent_events';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Recent inbound events',
            purpose: 'Summarises events received from the shops and the ERP, including the ones that failed. Returns no payloads.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.facts.read',
            auditAction: 'tool.hq_recent_events',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            idempotent: true,
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720],
                'only_failed' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'by_type' => ['type' => 'array'],
            'undelivered' => ['type' => 'array'],
            'window_hours' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $hours = (int) ($payload['hours'] ?? 24);
        $since = CarbonImmutable::now()->subHours($hours);

        $businessId = null;
        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                return ToolResult::failed("HQ has no business with the slug [{$slug}].");
            }
        }

        $summary = DB::table('events')
            ->where('created_at', '>=', $since)
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->tap(fn ($q) => $context->restrictBusinesses($q, 'business_id'))
            ->groupBy('type', 'status')
            ->select('type', 'status')
            ->selectRaw('count(*) as total')
            ->orderBy('type')
            ->get();

        $byType = [];
        foreach ($summary as $row) {
            $row = (array) $row;
            $byType[$row['type']] ??= ['type' => $row['type'], 'total' => 0, 'by_status' => []];
            $byType[$row['type']]['by_status'][$row['status']] = (int) $row['total'];
            $byType[$row['type']]['total'] += (int) $row['total'];
        }

        $undelivered = [];
        $failing = DomainEvent::query()
            ->whereIn('status', ['dead', 'failed'])
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->tap(fn ($q) => $context->restrictBusinesses($q, 'business_id'))
            ->latest('id')
            ->limit(min((int) ($payload['limit'] ?? 25), self::MAX))
            ->get();

        foreach ($failing as $event) {
            $undelivered[] = [
                'event_id' => $event->event_id,
                'type' => $event->type,
                'status' => $event->status,
                'aggregate' => $event->aggregate_type,
                'attempts' => $event->attempts,
                'occurred_at' => $event->occurred_at?->setTimezone($timezone)->toIso8601String(),
                'error' => $event->last_error === null ? null : mb_substr((string) $event->last_error, 0, 200),
            ];
        }

        return ToolResult::succeeded([
            'by_type' => array_values($byType),
            'undelivered' => $undelivered,
            'undelivered_total' => DomainEvent::query()->whereIn('status', ['dead', 'failed'])->count(),
            'window_hours' => $hours,
            'since' => $since->setTimezone($timezone)->toIso8601String(),
            'as_of' => CarbonImmutable::now($timezone)->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
