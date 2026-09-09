<?php

namespace App\Memory;

use App\Context\ContextBundle;
use App\Context\Provenance;
use App\Models\Memory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writing and reading what HQ remembers (Phase 2.5 F).
 *
 * ⛔ **Provenance is required on every write, and there is no default.** A memory row that dropped
 * its label would be an injection with a very long fuse: untrusted text arrives today, is
 * summarised into a belief, and is read as business data next quarter by an agent that never saw
 * where it came from. `recall()` returns labelled `ContextBlock`s for the same reason, so a
 * remembered sentence cannot enter a prompt as though the business had written it.
 *
 * ⛔ **Structured memory supersedes; episodic memory never does.** Correcting a delivery address
 * closes the old row and opens a new one. "The customer complained on 3 March" cannot be corrected,
 * because it happened, and a store that lets it be edited is a store that can be made to forget.
 */
final readonly class MemoryStore
{
    /** A current fact with a key. Supersedes whatever answered that key before. */
    public function remember(
        string $key,
        array $value,
        Provenance $provenance,
        array $scope = [],
        array $attributes = [],
    ): Memory {
        return DB::transaction(function () use ($key, $value, $provenance, $scope, $attributes) {
            $previous = Memory::query()
                ->where('kind', MemoryKind::Structured->value)
                ->where('key', $key)
                ->whereNull('valid_to')
                ->when(isset($scope['business_id']), fn ($q) => $q->where('business_id', $scope['business_id']), fn ($q) => $q->whereNull('business_id'))
                ->when(isset($scope['customer_id']), fn ($q) => $q->where('customer_id', $scope['customer_id']), fn ($q) => $q->whereNull('customer_id'))
                ->when(isset($scope['agent_key']), fn ($q) => $q->where('agent_key', $scope['agent_key']), fn ($q) => $q->whereNull('agent_key'))
                ->first();

            // Close the old row BEFORE inserting the new one: the partial unique index allows
            // exactly one live value per key, and doing it the other way round would be refused by
            // the database rather than merely being untidy.
            $previous?->forceFill(['valid_to' => now()])->save();

            return Memory::create([
                'uuid' => (string) Str::uuid(),
                'kind' => MemoryKind::Structured->value,
                'business_id' => $scope['business_id'] ?? null,
                'brand_id' => $scope['brand_id'] ?? null,
                'customer_id' => $scope['customer_id'] ?? null,
                'agent_key' => $scope['agent_key'] ?? null,
                'key' => $key,
                'value' => $value,
                'provenance' => $provenance->value,
                'source_ref' => $attributes['source_ref'] ?? null,
                'conversation_id' => $attributes['conversation_id'] ?? null,
                'valid_from' => now(),
                'supersedes_id' => $previous?->id,
                'data_class' => $attributes['data_class'] ?? 'internal',
                'retention_days' => $attributes['retention_days'] ?? null,
            ]);
        });
    }

    /** Something that happened, at a time. Never superseded, because it happened. */
    public function recordEpisode(string $content, Provenance $provenance, array $scope = [], array $attributes = []): Memory
    {
        return Memory::create([
            'uuid' => (string) Str::uuid(),
            'kind' => MemoryKind::Episodic->value,
            'business_id' => $scope['business_id'] ?? null,
            'brand_id' => $scope['brand_id'] ?? null,
            'customer_id' => $scope['customer_id'] ?? null,
            'agent_key' => $scope['agent_key'] ?? null,
            'content' => $content,
            'provenance' => $provenance->value,
            'source_ref' => $attributes['source_ref'] ?? null,
            'conversation_id' => $attributes['conversation_id'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'valid_from' => now(),
            'data_class' => $attributes['data_class'] ?? 'personal',
            'retention_days' => $attributes['retention_days'] ?? null,
        ]);
    }

    /** An inference. Carries a confidence, and may simply be wrong. */
    public function believe(string $content, float $confidence, Provenance $provenance, array $scope = [], array $attributes = []): Memory
    {
        return Memory::create([
            'uuid' => (string) Str::uuid(),
            'kind' => MemoryKind::Semantic->value,
            'business_id' => $scope['business_id'] ?? null,
            'brand_id' => $scope['brand_id'] ?? null,
            'customer_id' => $scope['customer_id'] ?? null,
            'agent_key' => $scope['agent_key'] ?? null,
            'content' => $content,
            'confidence' => $confidence,
            'provenance' => $provenance->value,
            'source_ref' => $attributes['source_ref'] ?? null,
            'conversation_id' => $attributes['conversation_id'] ?? null,
            'valid_from' => now(),
            'data_class' => $attributes['data_class'] ?? 'personal',
            'retention_days' => $attributes['retention_days'] ?? null,
        ]);
    }

    /**
     * What is remembered about a subject, as LABELLED context blocks.
     *
     * @param  list<MemoryKind>|null  $kinds  null for all three
     */
    public function recall(array $scope, ?array $kinds = null, int $limit = 50): ContextBundle
    {
        $rows = Memory::query()
            ->when(isset($scope['business_id']), fn ($q) => $q->where('business_id', $scope['business_id']))
            ->when(isset($scope['customer_id']), fn ($q) => $q->where('customer_id', $scope['customer_id']))
            ->when(isset($scope['agent_key']), fn ($q) => $q->where('agent_key', $scope['agent_key']))
            ->when($kinds !== null, fn ($q) => $q->whereIn('kind', array_map(static fn (MemoryKind $k) => $k->value, $kinds)))
            ->whereNull('valid_to')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $bundle = new ContextBundle;
        foreach ($rows as $row) {
            $bundle = $bundle->with($row->toContextBlock());
        }

        return $bundle;
    }
}
