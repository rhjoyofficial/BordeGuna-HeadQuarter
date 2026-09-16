<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * Graph webhook JSON for comments on a Page's posts, reduced to `InboundMessage`s (docs/72 §B,
 * docs/78 B.1).
 *
 * The document shape (Graph API webhooks, object `page`, field `feed`):
 *
 * ```
 * {"object":"page","entry":[{"id":"<page id>","time":1757757600,"changes":[
 *   {"field":"feed","value":{"item":"comment","verb":"add","comment_id":"<post>_<comment>","post_id":"<page>_<post>",
 *     "parent_id":"<page>_<post>","created_time":1757757600,"from":{"id":"<user id>","name":"..."},"message":"..."}}
 * ]}]}
 * ```
 *
 * ⛔ Pure. Only a comment being ADDED by someone other than the Page becomes a message: an edit, a
 * removal, a hide, a reaction, a like or a new post yields nothing, and so does the Page's own reply
 * (`from.id` equal to the Page). A comment is public, so the thread is one person's comments on one
 * post (`comment:<page>:<post>:<user>`), and a reply to it would be a public comment reply, which is
 * a later gate's send path. The identity is the commenter's Page-scoped user id, which Meta does not
 * relate to the same person's Messenger PSID: linking them stays a person's decision (docs/14).
 */
final class MetaCommentNormaliser implements InboundChannelAdapter
{
    public function channel(): Channel
    {
        return Channel::Comment;
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
            $page = (string) ($entry['id'] ?? '');

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'feed') {
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value) || ($value['item'] ?? null) !== 'comment' || ($value['verb'] ?? 'add') !== 'add') {
                    continue;
                }

                $from = (string) data_get($value, 'from.id', '');
                $commentId = (string) ($value['comment_id'] ?? '');
                $postId = (string) ($value['post_id'] ?? '');
                if ($page === '' || $from === '' || $commentId === '' || $postId === '' || $from === $page) {
                    continue;
                }

                $created = $value['created_time'] ?? null;
                $receivedAt = is_numeric($created)
                    ? CarbonImmutable::createFromTimestamp((int) $created)
                    : CarbonImmutable::now();

                $attachments = [];
                if (is_array($value['photo'] ?? null) || is_string($value['photo'] ?? null)) {
                    $attachments[] = ['type' => 'image', 'ref' => is_string($value['photo']) ? $value['photo'] : (string) data_get($value, 'photo.url', '')];
                }

                $metadata = array_filter([
                    'post_id' => $postId,
                    'parent_id' => isset($value['parent_id']) && $value['parent_id'] !== $postId ? (string) $value['parent_id'] : null,
                    'sender_name' => is_string(data_get($value, 'from.name')) ? data_get($value, 'from.name') : null,
                ], static fn ($v) => $v !== null);

                yield new InboundMessage(
                    channel: Channel::Comment,
                    platformAccountRef: $page,
                    externalThreadRef: "comment:{$page}:{$postId}:{$from}",
                    externalMessageRef: $commentId,
                    senderExternalId: $from,
                    body: isset($value['message']) ? (string) $value['message'] : null,
                    receivedAt: $receivedAt,
                    digest: hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE) ?: $commentId),
                    attachments: $attachments,
                    metadata: $metadata,
                );
            }
        }
    }
}
