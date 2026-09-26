<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * WhatsApp Cloud API webhook JSON, reduced to `InboundMessage`s and `DeliveryStatus`es (docs/88 A
 * point 2).
 *
 * The document shape (Meta, "messages webhook reference", read 22 September 2026):
 *
 * ```
 * {"object":"whatsapp_business_account","entry":[{"id":"<WABA id>","changes":[{"field":"messages","value":{
 *   "messaging_product":"whatsapp",
 *   "metadata":{"display_phone_number":"15550783881","phone_number_id":"106540352242922"},
 *   "contacts":[{"profile":{"name":"Sheena Nelson"},"wa_id":"16505551234"}],
 *   "messages":[{"from":"16505551234","id":"wamid...","timestamp":"1749416383","type":"text","text":{"body":"..."}}],
 *   "statuses":[{"id":"wamid...","status":"delivered","timestamp":"1750263773","recipient_id":"16505551234"}]
 * }}]}]}
 * ```
 *
 * ⛔ Pure, like the Meta normalisers: no token, no HTTP, no database, and a media file is never
 * fetched (its id is kept as a reference; the download URL Meta includes is short-lived and needs a
 * token). The platform account is the business phone number the message was sent TO
 * (`metadata.phone_number_id`, platform `whatsapp_number`); the thread is that number and the
 * customer's WhatsApp id, so the same person writing to two of the group's numbers is two threads.
 *
 * What yields nothing: a reaction (an emoji on an earlier message, not a message), a `system` notice
 * (a number change), a status (see `statuses()`), and any item missing its sender or id. A type HQ
 * does not read (`unsupported`, `order`, a future one) still yields a message with no body, so a person
 * sees that something arrived instead of it vanishing.
 */
final class WhatsAppCloudNormaliser implements InboundChannelAdapter
{
    public const OBJECT = 'whatsapp_business_account';

    /** Message types with a caption or a body HQ reads as the customer's words. */
    private const MEDIA = ['image', 'audio', 'video', 'document', 'sticker'];

    private const SKIPPED = ['reaction', 'system'];

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    /** @return iterable<InboundMessage> */
    public function normalise(array $payload): iterable
    {
        foreach ($this->values($payload) as $value) {
            $number = (string) data_get($value, 'metadata.phone_number_id', '');
            if ($number === '') {
                continue;
            }

            $names = [];
            foreach ((array) ($value['contacts'] ?? []) as $contact) {
                if (is_array($contact) && is_string($contact['wa_id'] ?? null) && is_string(data_get($contact, 'profile.name'))) {
                    $names[$contact['wa_id']] = data_get($contact, 'profile.name');
                }
            }

            foreach ((array) ($value['messages'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $from = (string) ($item['from'] ?? '');
                $id = (string) ($item['id'] ?? '');
                $type = (string) ($item['type'] ?? '');
                if ($from === '' || $id === '' || in_array($type, self::SKIPPED, true)) {
                    continue;
                }

                [$body, $attachments, $metadata] = $this->content($type, $item);

                $metadata += array_filter([
                    'wa_type' => $type,
                    'reply_to' => data_get($item, 'context.id'),
                    'forwarded' => data_get($item, 'context.forwarded') === true ? true : null,
                    'sender_name' => $names[$from] ?? null,
                ], static fn ($v) => $v !== null);

                yield new InboundMessage(
                    channel: Channel::Whatsapp,
                    platformAccountRef: $number,
                    externalThreadRef: "{$number}:{$from}",
                    externalMessageRef: $id,
                    senderExternalId: $from,
                    body: $body,
                    receivedAt: $this->at($item['timestamp'] ?? null),
                    digest: hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE) ?: $id),
                    attachments: $attachments,
                    metadata: $metadata,
                );
            }
        }
    }

    /**
     * What WhatsApp later said about messages the business number sent: `sent`, `delivered`, `read`,
     * `played` (a voice note first played) or `failed` with Meta's error.
     *
     * @return iterable<DeliveryStatus>
     */
    public function statuses(array $payload): iterable
    {
        foreach ($this->values($payload) as $value) {
            foreach ((array) ($value['statuses'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? '');
                $status = (string) ($item['status'] ?? '');
                if ($id === '' || ! in_array($status, DeliveryStatus::ALL, true)) {
                    continue;
                }

                $error = is_array($item['errors'][0] ?? null) ? $item['errors'][0] : null;

                yield new DeliveryStatus(
                    channel: Channel::Whatsapp,
                    providerRef: $id,
                    status: $status,
                    at: $this->at($item['timestamp'] ?? null),
                    recipientExternalId: isset($item['recipient_id']) ? (string) $item['recipient_id'] : null,
                    error: $error === null ? null : [
                        'code' => $error['code'] ?? null,
                        'title' => isset($error['title']) ? (string) $error['title'] : (isset($error['message']) ? (string) $error['message'] : null),
                        'details' => data_get($error, 'error_data.details'),
                    ],
                );
            }
        }
    }

    /** @return iterable<array<string, mixed>> each change value of field `messages` */
    private function values(array $payload): iterable
    {
        if (($payload['object'] ?? null) !== self::OBJECT) {
            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $product = $change['value']['messaging_product'] ?? 'whatsapp';
                if ($product !== 'whatsapp') {
                    continue;
                }

                yield $change['value'];
            }
        }
    }

    /**
     * @return array{0: ?string, 1: list<array{type: string, ref: string}>, 2: array<string, mixed>}
     */
    private function content(string $type, array $item): array
    {
        if ($type === 'text') {
            return [isset($item['text']['body']) ? (string) $item['text']['body'] : null, [], []];
        }

        if (in_array($type, self::MEDIA, true)) {
            $media = is_array($item[$type] ?? null) ? $item[$type] : [];
            $attachments = isset($media['id']) ? [['type' => $type, 'ref' => 'wa-media:'.$media['id']]] : [];
            $metadata = array_filter([
                'mime_type' => $media['mime_type'] ?? null,
                // A recording made with WhatsApp's own voice button, as opposed to an audio file (Meta).
                'voice' => ($media['voice'] ?? null) === true ? true : null,
                'filename' => $media['filename'] ?? null,
            ], static fn ($v) => $v !== null);

            return [isset($media['caption']) ? (string) $media['caption'] : null, $attachments, $metadata];
        }

        if ($type === 'interactive') {
            $kind = (string) data_get($item, 'interactive.type', '');
            $reply = is_array(data_get($item, "interactive.{$kind}")) ? data_get($item, "interactive.{$kind}") : [];

            // The title the customer tapped is what they "said"; the id is the business's own key for it.
            return [isset($reply['title']) ? (string) $reply['title'] : null, [], array_filter([
                'interactive_type' => $kind !== '' ? $kind : null,
                'interactive_id' => $reply['id'] ?? null,
            ], static fn ($v) => $v !== null)];
        }

        if ($type === 'button') {
            // A quick-reply button on a template the business sent.
            return [isset($item['button']['text']) ? (string) $item['button']['text'] : null, [], array_filter([
                'button_payload' => data_get($item, 'button.payload'),
            ], static fn ($v) => $v !== null)];
        }

        if ($type === 'location') {
            return [null, [], array_filter([
                'latitude' => data_get($item, 'location.latitude'),
                'longitude' => data_get($item, 'location.longitude'),
                'location_name' => data_get($item, 'location.name'),
            ], static fn ($v) => $v !== null)];
        }

        return [null, [], []];
    }

    private function at(mixed $timestamp): CarbonImmutable
    {
        // WhatsApp timestamps are Unix SECONDS as strings; Messenger's are milliseconds.
        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC') : CarbonImmutable::now('UTC');
    }
}
