<?php

namespace App\Models;

use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DRAFT read from a supplier document: fields with the text they came from, waiting for a person.
 * Nothing leaves HQ because of this row; a reviewed one can be offered to the ERP as a draft bill through the
 * approval gate, and that is a separate act.
 */
class IngestedDocument extends Model
{
    public const DRAFT = 'draft';

    public const REVIEWED = 'reviewed';

    public const REJECTED = 'rejected';

    public const SENT_TO_ERP = 'sent_to_erp';

    public const ERP_FAILED = 'erp_failed';

    protected $fillable = ['uuid', 'business_id', 'mail_message_id', 'mail_attachment_id', 'kind', 'status', 'source_text', 'extracted', 'extractor',
        'reviewed_by', 'reviewed_at', 'review_note', 'erp_draft_ref', 'erp_draft_error'];

    protected function casts(): array
    {
        return ['extracted' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }
}
