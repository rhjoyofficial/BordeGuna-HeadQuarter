<?php

namespace App\Conversation\Channels\Meta;

use App\Conversation\Channels\OutboundMessage;
use App\Gateway\Descriptor\Channel;
use App\Platform\Capabilities;
use App\Platform\Contracts\MessagingChannel;

/**
 * Sends one message from a business WhatsApp number through the Cloud API (docs/88 A point 2).
 *
 * Meta, "Send messages" (read 22 September 2026): `POST /v25.0/<WHATSAPP_BUSINESS_PHONE_NUMBER_ID>/messages`
 * with `messaging_product: whatsapp`, `recipient_type: individual`, `to`, and either `type: text` with
 * `text.body` or `type: template` with `template.name`, `template.language.code` and `components`;
 * success names the message in `messages[0].id`. "When the window closes, you can only send
 * pre-approved template messages": the window is enforced by the reply tool before this adapter is
 * reached, and Meta refuses a free-form message outside it anyway (131047, `outside_window`).
 *
 * The token is a system-user access token, vault provider `whatsapp_access_token`, account reference
 * the phone number id (platform `whatsapp_number`, the same reference the inbound normaliser gives).
 *
 * ⛔ Not registered in `PlatformRegistry`; see `MetaSendAdapter`.
 */
final class WhatsAppCloudChannel extends MetaSendAdapter
{
    public const TOKEN_PROVIDER = 'whatsapp_access_token';

    /** The text body limit (docs/88-q-record.md: the commonly documented 4,096 characters, not re-read). */
    public const MAX_CHARACTERS = 4096;

    public function platform(): string
    {
        return 'whatsapp_number';
    }

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function tokenProvider(): string
    {
        return self::TOKEN_PROVIDER;
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([MessagingChannel::SEND_SERVICE, MessagingChannel::SEND_TEMPLATE, MessagingChannel::RECEIVE]);
    }

    public function requiresApproval(): array
    {
        return [MessagingChannel::SEND_SERVICE, MessagingChannel::SEND_TEMPLATE];
    }

    protected function supportsTemplates(): bool
    {
        return true;
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
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipientExternalId,
        ];

        $body += $message->template !== null
            ? ['type' => 'template', 'template' => $message->template->toGraph()]
            : ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]];

        return ["{$message->platformAccountRef}/messages", $body];
    }

    protected function messageId(array $json): ?string
    {
        $id = $json['messages'][0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
