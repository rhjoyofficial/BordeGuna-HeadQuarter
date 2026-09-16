<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * Graph webhook JSON for an Instagram professional account's DMs, reduced to `InboundMessage`s
 * (docs/72 §B, docs/78 B.1).
 *
 * The document shape (Graph API webhooks, object `instagram`) is the Messenger one with a different
 * envelope object and ids:
 *
 * ```
 * {"object":"instagram","entry":[{"id":"<ig account id>","time":1757757600000,"messaging":[
 *   {"sender":{"id":"<igsid>"},"recipient":{"id":"<ig account id>"},"timestamp":1757757600123,
 *    "message":{"mid":"aWdf...","text":"...","attachments":[{"type":"image","payload":{"url":"..."}}]}}
 * ]}]}
 * ```
 *
 * ⛔ Pure, like the Messenger one: no token, no HTTP, no database. Echoes of our own messages, read
 * receipts, reactions and postbacks without text yield nothing. The account reference is the
 * Instagram account id, which HQ holds as a `platform_accounts` row of platform `instagram_account`;
 * the thread reference is `<ig account id>:<igsid>`; the identity is the IGSID (docs/14).
 */
final class MetaInstagramNormaliser implements InboundChannelAdapter
{
    public function channel(): Channel
    {
        return Channel::Instagram;
    }

    /** @return iterable<InboundMessage> */
    public function normalise(array $payload): iterable
    {
        if (($payload['object'] ?? null) !== 'instagram') {
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
                $account = (string) (data_get($item, 'recipient.id') ?? $entry['id'] ?? '');
                $mid = (string) ($message['mid'] ?? '');
                if ($sender === '' || $account === '' || $mid === '') {
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
                            'ref' => (string) (data_get($attachment, 'payload.url') ?? ''),
                        ];
                    }
                }

                $metadata = array_filter([
                    'reply_to' => data_get($message, 'reply_to.mid'),
                    // A reply to one of the account's stories carries the story's id; the text is the customer's.
                    'story_ref' => data_get($message, 'reply_to.story.id'),
                ], static fn ($v) => $v !== null);

                yield new InboundMessage(
                    channel: Channel::Instagram,
                    platformAccountRef: $account,
                    externalThreadRef: "{$account}:{$sender}",
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
