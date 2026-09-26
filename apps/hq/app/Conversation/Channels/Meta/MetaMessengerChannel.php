<?php

namespace App\Conversation\Channels\Meta;

use App\Conversation\Channels\OutboundMessage;
use App\Gateway\Descriptor\Channel;

/**
 * Sends one reply to a person on a Facebook Page's Messenger (docs/88 A point 2).
 *
 * Meta's Send API (read 22 September 2026): `POST /{PAGE-ID}/messages` with `recipient.id` the
 * person's Page-scoped id, `messaging_type` `RESPONSE` (a reply to a person who wrote to the Page) and
 * `message.text`; success is `{"recipient_id": "...", "message_id": "..."}`. Only `RESPONSE`: a message
 * outside the person's 24-hour window needs a message tag, which HQ does not use, and Meta refuses it
 * (code 10, subcode 2018278, classified `outside_window`).
 *
 * The token is the Page's own access token, vault provider `meta_page_token`, account reference the
 * Page id. ⛔ Not registered in `PlatformRegistry`; see `MetaSendAdapter`.
 */
final class MetaMessengerChannel extends MetaSendAdapter
{
    public const TOKEN_PROVIDER = 'meta_page_token';

    /** Meta's Send API text limit, in characters (docs/88-q-record.md: not re-read on 22 September). */
    public const MAX_CHARACTERS = 2000;

    public function platform(): string
    {
        return 'facebook_page';
    }

    public function channel(): Channel
    {
        return Channel::Messenger;
    }

    public function tokenProvider(): string
    {
        return self::TOKEN_PROVIDER;
    }

    protected function fits(string $body): bool
    {
        return mb_strlen($body) <= self::MAX_CHARACTERS;
    }

    protected function limitDescription(): string
    {
        return self::MAX_CHARACTERS.' characters';
    }

    protected function request(OutboundMessage $message): array
    {
        return ["{$message->platformAccountRef}/messages", [
            'recipient' => ['id' => $message->recipientExternalId],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $message->body],
        ]];
    }

    protected function messageId(array $json): ?string
    {
        return is_string($json['message_id'] ?? null) ? $json['message_id'] : null;
    }
}
