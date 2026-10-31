<?php

namespace App\Models;

use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo, video or file a person attached in Ask HQ: stored per business, with a retention date. */
class ChatAttachment extends Model
{
    protected $fillable = ['uuid', 'user_id', 'business_id', 'media_asset_id', 'ask_message_id', 'original_name', 'kind', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * What this person may open: their own uploads, and the uploads of the businesses they may see. A row with no
     * business is its uploader's alone (fail closed for everyone restricted).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = app(BusinessScope::class)->all($user);
        if ($scope === null) {
            return $query->where('status', '!=', 'purged');
        }

        return $query->where('status', '!=', 'purged')->where(fn (Builder $q) => $q->where('user_id', $user->getKey())->orWhereIn('business_id', $scope));
    }
}
