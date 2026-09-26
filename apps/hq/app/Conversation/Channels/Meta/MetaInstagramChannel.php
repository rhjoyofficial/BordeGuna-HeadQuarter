<?php

namespace App\Conversation\Channels\Meta;

use App\Conversation\Channels\OutboundMessage;
use App\Gateway\Descriptor\Channel;

/**
 * Sends one reply to a person in an Instagram professional account's DMs (docs/88 A point 2).
 *
 * Messenger Platform for Instagram, "Send a Message" (read 22 September 2026): host
 * `graph.facebook.com`, path `/PAGE-ID/messages`, "a Page access token requested from a person who can
 * perform the MESSAGE task on the Facebook Page" linked to the Instagram account, body
 * `{"recipient": {"id": "<IGSID>"}, "message": {"text": "..."}}`, success `{"recipient_id", "message_id"}`;
 * "Message text must be UTF-8 and be 1,000 bytes or less."
 *
 * HQ holds the Instagram account (platform `instagram_account`, reference the account id Meta names in
 * the webhook), not the Page id. So the path is `me/messages`: with a Page access token Graph resolves
 * `me` to that Page. ⚠ That resolution is Graph's general rule, not a sentence of the Instagram page;
 * `docs/88-q-record.md` marks it to be proven at P3-G4 on a test account. The token is vault provider
 * `meta_instagram_token` under the Instagram account's reference.
 *
 * ⛔ Not registered in `PlatformRegistry`; see `MetaSendAdapter`.
 */
final class MetaInstagramChannel extends MetaSendAdapter
{
    public const TOKEN_PROVIDER = 'meta_instagram_token';

    public const MAX_BYTES = 1000;

    public function platform(): string
    {
        return 'instagram_account';
    }

    public function channel(): Channel
    {
        return Channel::Instagram;
    }

    public function tokenProvider(): string
    {
        return self::TOKEN_PROVIDER;
    }

    protected function fits(string $body): bool
    {
        return strlen($body) <= self::MAX_BYTES;
    }

    protected function limitDescription(): string
    {
        return self::MAX_BYTES.' bytes of UTF-8; Bangla takes three bytes a letter';
    }

    protected function request(OutboundMessage $message): array
    {
        return ['me/messages', [
            'recipient' => ['id' => $message->recipientExternalId],
            'message' => ['text' => $message->body],
        ]];
    }

    protected function messageId(array $json): ?string
    {
        return is_string($json['message_id'] ?? null) ? $json['message_id'] : null;
    }
}
