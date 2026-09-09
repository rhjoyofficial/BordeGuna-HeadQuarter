<?php

namespace App\Models;

use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Gateway\Descriptor\DataClass;
use App\Memory\MemoryKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What HQ remembers, in three kinds that are not interchangeable (Phase 2.5 F, see MemoryKind).
 *
 * ⛔ Provenance travels INTO memory (ADR-024). A remembered sentence that came from a customer is
 * still untrusted a year later, and a memory row that dropped its label would be an injection with
 * a very long fuse: the payload arrives today, is summarised into a belief, and is read as
 * business data next quarter by an agent that never saw where it came from.
 */
class Memory extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid', 'kind', 'business_id', 'brand_id', 'customer_id', 'agent_key', 'key', 'value',
        'content', 'provenance', 'source_ref', 'conversation_id', 'confidence', 'occurred_at',
        'valid_from', 'valid_to', 'supersedes_id', 'data_class', 'retention_days',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MemoryKind::class,
            'provenance' => Provenance::class,
            'data_class' => DataClass::class,
            'value' => 'array',
            'confidence' => 'float',
            'occurred_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function isLive(): bool
    {
        return $this->valid_to === null;
    }

    /** The memory as a labelled context block, carrying its original provenance forward. */
    public function toContextBlock(): ContextBlock
    {
        $content = $this->kind === MemoryKind::Structured
            ? $this->key.': '.json_encode($this->value)
            : (string) $this->content;

        return new ContextBlock(
            provenance: $this->provenance,
            source: "memory:{$this->kind->value}:{$this->id}",
            content: $content,
            metadata: array_filter(['confidence' => $this->confidence]),
        );
    }
}
