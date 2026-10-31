<?php

namespace App\Media\Upload;

use App\Models\ChatAttachment;
use App\Models\MailAttachment;
use App\Models\MediaAsset;
use App\Platform\Contracts\StorageDriver;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Deletes chat attachments past their retention date (docs/144-media-in-chat). The file's bytes go only when
 * nothing else still refers to the asset: the asset store de-duplicates by content, so two businesses that
 * upload the same bytes share one asset, and one expiring must not remove the other's file.
 */
final class MediaRetention
{
    public function __construct(private readonly StorageDriver $storage, private readonly Auditor $auditor) {}

    /** @return array{attachments: int, assets: int} */
    public function purgeExpired(): array
    {
        $attachments = $assets = 0;

        ChatAttachment::query()->where('status', '!=', 'purged')->where('expires_at', '<', now())->orderBy('id')->each(function (ChatAttachment $a) use (&$attachments, &$assets) {
            $attachments++;
            $this->discard($a) && $assets++;
        });

        return ['attachments' => $attachments, 'assets' => $assets];
    }

    /** Mark one attachment purged and remove its bytes when nothing else refers to them. True when the bytes went. */
    public function discard(ChatAttachment $a): bool
    {
        return DB::transaction(function () use ($a): bool {
            $a->forceFill(['status' => 'purged'])->save();
            $asset = $a->asset;
            $deleted = false;
            if ($asset !== null && $this->unreferenced($asset)) {
                $this->storage->delete($asset->storage_key);
                $asset->delete();
                $deleted = true;
            }
            $this->auditor->recordSystem('chat_attachment.purged', null, null, ['attachment_uuid' => $a->uuid, 'asset_deleted' => $deleted]);

            return $deleted;
        });
    }

    private function unreferenced(MediaAsset $asset): bool
    {
        return ! ChatAttachment::query()->where('media_asset_id', $asset->id)->where('status', '!=', 'purged')->exists()
            && ! MailAttachment::query()->where('media_asset_id', $asset->id)->exists()
            && ! DB::table('media_drafts')->where('result_media_asset_id', $asset->id)->exists();
    }
}
