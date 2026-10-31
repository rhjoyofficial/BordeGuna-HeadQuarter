<?php

namespace App\Http\Controllers\Connectors;

use App\Http\Controllers\Controller;
use App\Media\MediaAssetService;
use App\Media\Upload\AttachmentRejected;
use App\Media\Upload\ChatMediaUploader;
use App\Models\AskConversation;
use App\Models\ChatAttachment;
use App\Support\Features\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Photos, videos and files attached in Ask HQ (docs/144-media-in-chat). The upload is behind the flag
 * `connectors.media_upload` and the permission `hq.ask.use`; what comes back is only ever served as a download
 * (images may show inline), with `nosniff` and a sandbox policy, never rendered as a page.
 */
final class ChatMediaController extends Controller
{
    public function store(Request $request, ChatMediaUploader $uploader, Features $features): JsonResponse
    {
        if (! $features->enabled('connectors.media_upload')) {
            return response()->json(['error' => 'Attaching files is not switched on yet.'], 404);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('hq.media.limits.video_mb', 60) * 1024],
            'business_id' => ['nullable', 'integer'],
        ]);
        $file = $request->file('file');
        $bytes = (string) file_get_contents((string) $file->getRealPath());

        try {
            $attachment = $uploader->store($request->user(), $bytes, (string) $file->getClientOriginalName(), isset($data['business_id']) ? (int) $data['business_id'] : null);
        } catch (AttachmentRejected $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'uuid' => $attachment->uuid, 'name' => $attachment->original_name, 'kind' => $attachment->kind, 'business_id' => $attachment->business_id,
            'expires_at' => $attachment->expires_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Link the person's own stored uploads to the question they just asked: the latest of their own question messages in one of their
     * own conversations. An upload that is not theirs, or is already attached, is left out; the question itself is untouched.
     */
    public function attach(Request $request, AskConversation $conversation): JsonResponse
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->getKey(), 404);
        $data = $request->validate(['attachment_uuids' => ['required', 'array', 'max:6'], 'attachment_uuids.*' => ['uuid']]);

        $message = $conversation->messages()->where('role', 'user')->latest('id')->first();
        abort_if($message === null, 404);
        $linked = ChatAttachment::query()->whereIn('uuid', $data['attachment_uuids'])->where('user_id', $request->user()->getKey())->where('status', 'stored')
            ->update(['ask_message_id' => $message->id, 'status' => 'attached']);

        return response()->json(['linked' => $linked]);
    }

    public function show(Request $request, string $uuid, MediaAssetService $assets): Response
    {
        $attachment = ChatAttachment::query()->visibleTo($request->user())->where('uuid', $uuid)->first();
        abort_if($attachment === null || $attachment->asset === null, 404);
        $bytes = $assets->contents($attachment->asset);
        abort_if($bytes === null, 404);

        $inline = in_array($attachment->kind, ['image', 'audio'], true);

        return response($bytes, 200, [
            'Content-Type' => $attachment->asset->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.str_replace(['"', '\\'], '', $attachment->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
