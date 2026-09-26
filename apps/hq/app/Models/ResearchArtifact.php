<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The research engine's only output (docs/85 C.6, ADR-051 point 2). Append-only in the database: a
 * refreshed question is a new version, never an edit.
 */
class ResearchArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'task_id', 'version', 'contract_version', 'question', 'scope', 'sources_used',
        'sources_discarded', 'claims', 'budget_spent', 'as_of', 'digest', 'filed_by',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'sources_used' => 'array',
            'sources_discarded' => 'array',
            'claims' => 'array',
            'budget_spent' => 'array',
            'as_of' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, mixed>|null */
    public function claim(string $id): ?array
    {
        foreach ($this->claims ?? [] as $claim) {
            if (($claim['id'] ?? null) === $id) {
                return $claim;
            }
        }

        return null;
    }
}
