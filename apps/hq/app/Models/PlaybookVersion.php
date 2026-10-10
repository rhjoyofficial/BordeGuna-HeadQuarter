<?php

namespace App\Models;

use App\Playbooks\PlaybookKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one playbook: written business guidance for a business, a brand (or business-wide)
 * and a kind (docs/112 §2). The body is prose the owner wrote; the version is the contract a run names.
 *
 * ⛔ Immutable once past `draft` at the database level (pgsql triggers): content changes mean a new draft.
 * ⛔ At most one version is active per (business, brand-or-business-wide, kind) (partial unique index).
 * ⛔ Activation is the act of a named person holding `hq.playbooks.activate`, through `Playbooks`; the
 *    model never chooses a playbook, a prompt never overrides one, and nothing that decides reads one.
 */
class PlaybookVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const REVIEWED = 'reviewed';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::DRAFT, self::IN_REVIEW, self::REVIEWED, self::ACTIVE, self::RETIRED, self::WITHDRAWN];

    protected $fillable = [
        'uuid', 'business_id', 'brand_id', 'kind', 'version', 'status', 'title', 'summary', 'body', 'content_digest', 'supersedes_id',
        'drafted_by', 'drafted_at', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
        'activated_by', 'activated_at', 'activation_reason', 'retired_by', 'retired_at', 'retirement_reason',
        'withdrawn_by', 'withdrawn_at', 'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'kind' => PlaybookKind::class,
            'drafted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PlaybookVersionEvent::class, 'playbook_version_id')->orderBy('id');
    }

    public function drafter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Every version of the same key (business, brand-or-business-wide, kind), newest first. */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'business_id', 'business_id')
            ->where('kind', $this->kind->value)
            ->where(fn ($q) => $this->brand_id === null ? $q->whereNull('brand_id') : $q->where('brand_id', $this->brand_id))
            ->orderByDesc('version');
    }

    /** The key, as a short string for logs and block sources: `laladia/laladia/product_claims`. */
    public function keyLabel(): string
    {
        return ($this->business?->slug ?? "business:{$this->business_id}").'/'.($this->brand?->slug ?? 'business').'/'.$this->kind->value;
    }
}
