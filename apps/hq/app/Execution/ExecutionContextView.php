<?php

namespace App\Execution;

use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\User;

/**
 * The recorded `eec.v1` context of one run, shaped for a screen (docs/81 point 3).
 *
 * Read-only, and it resolves NOTHING: it reads what `ExecutionContextResolver::record()` stored on
 * the run when it ran, and looks up names for the ids in it. What a run could do was decided then;
 * a screen that re-resolved it would show today's authority under yesterday's run.
 *
 * ⚠ A run from before ADR-047 has no record. That is said as `before_adr_047`, never as an empty
 * context, because an empty context reads as "nothing narrowed this run".
 *
 * ⚠ A refused context has no run row at all (the resolver refuses before the runner creates one);
 * it lives only in the audit log as `agent_run.context_refused`. There is nothing here to show for
 * it, and nothing is invented.
 *
 * Nothing secret can leave through this: the record holds ids, keys, permission names and
 * sentences, and this adds display names. No email, no credential.
 */
final class ExecutionContextView
{
    public const BEFORE_ADR_047 = 'before_adr_047';

    public const RECORDED = 'recorded';

    /**
     * @param  list<int>|null  $viewerBusinessIds  the viewer's own business scope; null is unrestricted
     * @return array<string, mixed>
     */
    public function for(AgentRun $run, ?array $viewerBusinessIds): array
    {
        $record = $run->execution_context;

        if (! is_array($record) || $record === []) {
            return ['state' => self::BEFORE_ADR_047];
        }

        $requesterId = $record['requester']['user_id'] ?? null;
        $enablerId = $record['enabler']['user_id'] ?? null;
        $names = User::query()->whereIn('id', array_filter([$requesterId, $enablerId]))->pluck('name', 'id');

        $scope = (array) ($record['scope'] ?? []);

        return [
            'state' => self::RECORDED,
            'version' => $record['resolver_version'] ?? null,
            'requester' => [
                'user_id' => $requesterId,
                'name' => $requesterId === null ? null : ($names[$requesterId] ?? null),
                'kind' => $record['requester']['kind'] ?? null,
                'as_enabled' => (bool) ($record['requester']['as_enabled'] ?? false),
                'basis' => $record['requester']['basis'] ?? null,
                'is_enabler' => (bool) ($record['requester_is_enabler'] ?? false),
            ],
            'enabler' => [
                'user_id' => $enablerId,
                'name' => $enablerId === null ? null : ($names[$enablerId] ?? null),
            ],
            'acting_identity' => $record['acting_identity'] ?? null,
            'agent' => [
                'key' => $record['agent']['key'] ?? $run->agent_key,
                'role' => $record['agent']['role'] ?? null,
            ],
            'entry_point' => $record['entry_point'] ?? null,
            'environment' => $record['environment'] ?? null,
            'business_scope' => $this->businesses($scope, $viewerBusinessIds),
            'brand_scope' => $this->brands($scope, $viewerBusinessIds),
            'tools' => array_values((array) ($record['tools'] ?? [])),
            'permissions' => array_values((array) ($record['permissions'] ?? [])),
            'narrowed' => array_values(array_map(static fn ($n) => [
                'term' => (string) ($n['term'] ?? ''), 'by' => (string) ($n['by'] ?? ''), 'why' => (string) ($n['why'] ?? ''),
            ], (array) ($record['narrowed'] ?? []))),
            'decided_per_call' => array_values((array) ($record['decided_per_call'] ?? [])),
            'digest' => $record['digest'] ?? $run->execution_context_digest,
            'resolved_at' => $this->resolvedAt($run),
        ];
    }

    /**
     * Names only for businesses the VIEWER may see. The rest are counted: a name is a disclosure,
     * and the run page is already scoped for exactly that reason.
     *
     * @return array<string, mixed>
     */
    private function businesses(array $scope, ?array $viewerBusinessIds): array
    {
        $ids = $scope['business_ids'] ?? null;
        $visible = fn (int $id): bool => $viewerBusinessIds === null || in_array($id, $viewerBusinessIds, true);

        $named = [];
        $hidden = 0;
        if (is_array($ids)) {
            $names = Business::query()->whereIn('id', $ids)->pluck('name', 'id');
            foreach ($ids as $id) {
                $id = (int) $id;
                $visible($id) ? $named[] = ['id' => $id, 'name' => (string) ($names[$id] ?? "business #{$id}")] : $hidden++;
            }
        }

        $requested = $scope['requested_business_id'] ?? null;

        return [
            'group_wide' => $ids === null,
            'businesses' => $named,
            'outside_your_view' => $hidden,
            'requested' => $requested === null || ! $visible((int) $requested) ? null : [
                'id' => (int) $requested,
                'name' => (string) (Business::query()->whereKey($requested)->value('name') ?? "business #{$requested}"),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function brands(array $scope, ?array $viewerBusinessIds): array
    {
        $ids = $scope['brand_ids'] ?? null;
        $rows = Brand::query()
            ->whereIn('id', array_filter([...(is_array($ids) ? $ids : []), $scope['requested_brand_id'] ?? null]))
            ->get(['id', 'name', 'business_id'])->keyBy('id');
        $visible = fn ($brand): bool => $brand !== null && ($viewerBusinessIds === null || in_array((int) $brand->business_id, $viewerBusinessIds, true));

        $named = [];
        $hidden = 0;
        foreach (is_array($ids) ? $ids : [] as $id) {
            $brand = $rows[(int) $id] ?? null;
            $visible($brand) ? $named[] = ['id' => (int) $id, 'name' => (string) $brand->name] : $hidden++;
        }

        $requested = $rows[(int) ($scope['requested_brand_id'] ?? 0)] ?? null;

        return [
            'every_brand' => $ids === null,
            'brands' => $named,
            'outside_your_view' => $hidden,
            'requested' => $visible($requested) ? ['id' => (int) $requested->id, 'name' => (string) $requested->name] : null,
        ];
    }

    /**
     * The record holds no timestamp of its own; the audit row written in the same call does. Null
     * when that row is missing (its write is allowed to fail without ending the run), and the page
     * says so rather than borrowing the run's start time.
     */
    private function resolvedAt(AgentRun $run): ?string
    {
        $row = AuditLog::query()
            ->where('action', 'agent_run.context_resolved')
            ->where('target_type', $run->getMorphClass())
            ->where('target_id', (string) $run->getKey())
            ->orderBy('id')
            ->first(['id', 'occurred_at']);

        return $row?->occurred_at?->toIso8601String();
    }
}
