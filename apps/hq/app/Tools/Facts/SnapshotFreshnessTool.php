<?php

namespace App\Tools\Facts;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How old is everything HQ holds (docs/48 N.4, closing a `docs/45` gap).
 *
 * ⭐ **This is the tool that makes the other answers honest.** HQ is a read-model store (ADR-007):
 * every number it can produce is a copy of a fact that lives somewhere else, and a copy has an age.
 * An operator who acts on a stale figure without being told it was stale has been misled by an
 * accurate number, which is worse than being told nothing - so the agents are required to lead with
 * this, and this is where they get it.
 *
 * ⚠ **Pulled forward from M7 deliberately.** `docs/48` schedules `facts.snapshot_freshness` in M7
 * alongside its alert and its dashboard tile. The TOOL is brought forward because the analytics
 * agent's honesty requirement is unimplementable without it; the alert and the dashboard stay in
 * M7 where they belong. Recorded so the two milestones' scopes do not quietly blur.
 *
 * ⚠ It reports staleness; it never refreshes anything. Refreshing is `erp.poll_*`, which is an R3
 * write and is not something a question about freshness should trigger as a side effect.
 */
final class SnapshotFreshnessTool implements Tool
{
    /** Beyond this, a snapshot is called stale rather than merely old. */
    private const STALE_AFTER_MINUTES = 120;

    public function key(): string
    {
        return 'facts.snapshot_freshness';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Read-model freshness',
            purpose: 'Reports how old each HQ read model is, per business, so an answer can say what it does not cover.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.facts.read',
            auditAction: 'tool.facts_snapshot_freshness',
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
            'properties' => ['business_slug' => ['type' => 'string', 'maxLength' => 64]],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'sources' => ['type' => 'array'],
            'stale_count' => ['type' => 'integer'],
            'checked_at' => ['type' => 'string'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $now = CarbonImmutable::now($timezone);

        $businessId = null;
        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                return ToolResult::failed("HQ has no business with the slug [{$slug}].");
            }
        }

        $sources = [
            ...$this->erpSnapshots($businessId, $context, $now, $timezone),
            ...$this->orderFacts($businessId, $context, $now, $timezone),
        ];

        return ToolResult::succeeded([
            'sources' => $sources,
            'stale_count' => count(array_filter($sources, static fn (array $s) => $s['stale'] === true)),
            'stale_after_minutes' => self::STALE_AFTER_MINUTES,
            'checked_at' => $now->toIso8601String(),
            'timezone' => $timezone,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function erpSnapshots(?int $businessId, ToolContext $context, CarbonImmutable $now, string $timezone): array
    {
        $query = DB::table('facts_erp_snapshots as s')
            ->join('businesses as b', 'b.id', '=', 's.business_id')
            ->select('b.slug as business', 's.kind', 's.as_of', 's.polled_at');

        $context->restrictBusinesses($query, 's.business_id');

        if ($businessId !== null) {
            $query->where('s.business_id', $businessId);
        }

        $out = [];
        foreach ($query->orderBy('b.slug')->orderBy('s.kind')->get() as $row) {
            $row = (array) $row;
            // `polled_at` is when HQ asked; `as_of` is what the ERP said the data was. The older of
            // the two is the honest age, because a fresh poll of a stale report is still stale.
            $out[] = $this->describe(
                "erp:{$row['business']}/{$row['kind']}",
                $row['business'],
                $this->older($row['as_of'] ?? null, $row['polled_at'] ?? null),
                $now,
                $timezone,
            );
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function orderFacts(?int $businessId, ToolContext $context, CarbonImmutable $now, string $timezone): array
    {
        $query = DB::table('facts_orders as f')
            ->join('stores as st', 'st.id', '=', 'f.store_id')
            ->join('businesses as b', 'b.id', '=', 'f.business_id')
            ->groupBy('b.slug', 'st.slug')
            ->select('b.slug as business', 'st.slug as store')
            ->selectRaw('max(f.synced_at) as newest, count(*) as rows_held');

        $context->restrictBusinesses($query, 'f.business_id');

        if ($businessId !== null) {
            $query->where('f.business_id', $businessId);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $described = $this->describe(
                "facts_orders:{$row['store']}",
                $row['business'],
                $row['newest'] ?? null,
                $now,
                $timezone,
            );
            $described['rows_held'] = (int) $row['rows_held'];
            $out[] = $described;
        }

        return $out;
    }

    private function describe(string $source, ?string $business, ?string $at, CarbonImmutable $now, string $timezone): array
    {
        if ($at === null || $at === '') {
            // ⚠ Never absent, never null-and-silent. "HQ has never held this" is a finding.
            return [
                'source' => $source,
                'business' => $business,
                'as_of' => null,
                'age_minutes' => null,
                'stale' => true,
                'note' => 'HQ has never recorded this source. Treat every figure that would rest on it as unavailable, not as zero.',
            ];
        }

        $moment = CarbonImmutable::parse($at)->setTimezone($timezone);
        $age = (int) $moment->diffInMinutes($now);

        return [
            'source' => $source,
            'business' => $business,
            'as_of' => $moment->toIso8601String(),
            'age_minutes' => $age,
            'stale' => $age > self::STALE_AFTER_MINUTES,
            'note' => null,
        ];
    }

    private function older(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return CarbonImmutable::parse($a)->lessThan(CarbonImmutable::parse($b)) ? $a : $b;
    }
}
