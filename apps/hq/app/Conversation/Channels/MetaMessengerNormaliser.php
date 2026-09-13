<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * Graph webhook JSON for a Page's Messenger, reduced to `InboundMessage`s (docs/72 §B).
 *
 * The document shape (Graph API webhooks, object `page`):
 *
 * ```
 * {"object":"page","entry":[{"id":"<page id>","time":1757757600000,"messaging":[
 *   {"sender":{"id":"<psid>"},"recipient":{"id":"<page id>"},"timestamp":1757757600123,
 *    "message":{"mid":"m_...","text":"...","attachments":[{"type":"image","payload":{"url":"..."}}]}}
 * ]}]}
 * ```
 *
 * ⛔ Pure. No token, no HTTP, no database. A `messaging` item that is not a message (a delivery
 * receipt, a read receipt, a postback without text, the Page's own echo) yields nothing here; those
 * are a later gate's concern and dropping them is the honest default.
 *
 * The thread reference is `<page id>:<psid>`, because Messenger has no thread id of its own and the
 * same person may write to two of the group's Pages: that is two threads, one customer.
 */
final class MetaMessengerNormaliser implements InboundChannelAdapter
{
    public function channel(): Channel
    {
        return Channel::Messenger;
    }

    /** @return iterable<InboundMessage> */
    public function normalise(array $payload): iterable
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $message = $item['message'] ?? null;
                if (! is_array($message) || ($message['is_echo'] ?? false) === true) {
                    continue;
                }

                $sender = (string) data_get($item, 'sender.id', '');
                $page = (string) (data_get($item, 'recipient.id') ?? $entry['id'] ?? '');
                $mid = (string) ($message['mid'] ?? '');
                if ($sender === '' || $page === '' || $mid === '') {
                    continue;
                }

                $timestamp = $item['timestamp'] ?? $entry['time'] ?? null;
                $receivedAt = is_numeric($timestamp)
                    ? CarbonImmutable::createFromTimestampMs((int) $timestamp)
                    : CarbonImmutable::now();

                $attachments = [];
                foreach ((array) ($message['attachments'] ?? []) as $attachment) {
                    if (is_array($attachment)) {
                        $attachments[] = [
                            'type' => (string) ($attachment['type'] ?? 'file'),
                            'ref' => (string) (data_get($attachment, 'payload.url') ?? data_get($attachment, 'payload.sticker_id') ?? ''),
                        ];
                    }
                }

                $metadata = array_filter([
                    'quick_reply' => data_get($message, 'quick_reply.payload'),
                    'reply_to' => data_get($message, 'reply_to.mid'),
                ], static fn ($v) => $v !== null);

                yield new InboundMessage(
                    channel: Channel::Messenger,
                    platformAccountRef: $page,
                    externalThreadRef: "{$page}:{$sender}",
                    externalMessageRef: $mid,
                    senderExternalId: $sender,
                    body: isset($message['text']) ? (string) $message['text'] : null,
                    receivedAt: $receivedAt,
                    digest: hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE) ?: $mid),
                    attachments: $attachments,
                    metadata: $metadata,
                );
            }
        }
    }
}
