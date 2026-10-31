<?php

namespace App\Media\Upload;

use App\Context\Provenance;
use App\Media\MediaAssetService;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Support\Str;

/**
 * Stores a photo, video or file a person attaches in Ask HQ (docs/144-media-in-chat).
 *
 * ⛔ **Per-business scope, fail closed.** A person limited to some businesses must name one of them; naming
 * another, or none, is refused. A person with the whole group may leave it unset (an HQ-level file).
 * ⛔ **Bytes decide.** `AttachmentGuard` reads the real type from the content; an infected scan refuses the file;
 * with `hq.media.scan_required` an unscanned file is refused too. Untrusted provenance throughout: a file is
 * data a person looks at, never an instruction to HQ, and nothing here reads, transcribes or describes it.
 * ⭐ **Retention.** Every file expires (`hq.media.retention_days`, default 90) and `MediaRetention` deletes it.
 */
final class ChatMediaUploader
{
    public function __construct(
        private readonly AttachmentGuard $guard,
        private readonly MediaAssetService $assets,
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>  $kinds
     * @param  bool  $personal  a file with no business that only its uploader may open (a voice recording); never a way around the business rule for a business file
     *
     * @throws AttachmentRejected
     */
    public function store(User $user, string $bytes, string $originalName, ?int $businessId, array $kinds = ['image', 'video', 'document'], bool $personal = false, ?int $retentionDays = null): ChatAttachment
    {
        if (! $personal && $this->scope->isRestricted($user) && $businessId === null) {
            throw new AttachmentRejected('Choose which of your businesses this file belongs to.');
        }
        if (! $this->scope->allows($user, $businessId)) {
            throw new AttachmentRejected('You may not attach files for that business.');
        }
        if ($businessId !== null && ! Business::query()->whereKey($businessId)->exists()) {
            throw new AttachmentRejected('That business does not exist.');
        }

        ['mime' => $mime, 'kind' => $kind] = $this->guard->inspect($bytes, $kinds);

        [$verdict, $detail] = $this->scanner()->scan($bytes);
        if ($verdict === AttachmentScanner::INFECTED) {
            $this->auditor->record('chat_attachment.rejected_infected', null, null, ['signature' => $detail, 'bytes' => strlen($bytes)], actorUserId: $user->getKey());
            throw new AttachmentRejected('The virus scan found a threat in this file, so it was not stored.');
        }
        if ($verdict === AttachmentScanner::UNSCANNED && config('hq.media.scan_required', false)) {
            throw new AttachmentRejected('This file could not be virus-scanned, so it was not stored.');
        }

        $days = max(1, $retentionDays ?? (int) config('hq.media.retention_days', 90));
        $expires = now()->addDays($days);
        $asset = $this->assets->store($bytes, $mime, $kind, Provenance::ThirdParty, [
            'business_id' => $businessId, 'source_ref' => 'chat_upload', 'retention_days' => $days,
            'expires_at' => $expires, 'created_by' => $user->getKey(), 'data_class' => 'internal',
        ]);

        $attachment = ChatAttachment::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'business_id' => $businessId,
            'media_asset_id' => $asset->id, 'original_name' => self::cleanName($originalName), 'kind' => $kind,
            'status' => 'stored', 'expires_at' => $expires,
        ]);
        $this->auditor->record('chat_attachment.stored', $attachment, null, [
            'kind' => $kind, 'mime' => $mime, 'bytes' => strlen($bytes), 'business_id' => $businessId, 'scan' => $verdict,
        ], actorUserId: $user->getKey());

        return $attachment;
    }

    private function scanner(): AttachmentScanner
    {
        return Scanners::make();
    }

    /** A name that is only a name: no path, no control characters, bounded. */
    public static function cleanName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[\x00-\x1F\x7F]+/u', '', $base) ?? '';
        $base = trim($base) === '' ? 'file' : $base;

        return mb_substr($base, 0, 120);
    }
}
