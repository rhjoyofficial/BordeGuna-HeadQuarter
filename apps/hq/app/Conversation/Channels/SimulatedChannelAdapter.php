<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * A channel that exists only inside the process (docs/72 §B, gates P3-G1 and P3-G2).
 *
 * Inbound, it turns a scripted conversation into `InboundMessage`s exactly as a real adapter would,
 * so the conversation core, the state machine and the support agent can be driven end to end with
 * no provider. Outbound, `send()` records a receipt in memory and returns it; nothing leaves.
 *
 * The scripted payload shape:
 *
 * ```
 * ['page' => 'sim-page', 'messages' => [
 *     ['from' => 'psid-1', 'text' => 'আমার অর্ডার কোথায়?', 'mid' => 'm-1', 'at' => '2026-09-13T10:00:00+06:00'],
 * ]]
 * ```
 */
final class SimulatedChannelAdapter implements InboundChannelAdapter, OutboundChannelAdapter
{
    public const DEFAULT_PAGE = 'sim-page';

    /** @var list<array{message: OutboundMessage, receipt: DeliveryReceipt}> */
    private array $sent = [];

    public function __construct(private readonly Channel $channel = Channel::Messenger) {}

    public function channel(): Channel
    {
        return $this->channel;
    }

    /** @return iterable<InboundMessage> */
    public function normalise(array $payload): iterable
    {
        $page = (string) ($payload['page'] ?? self::DEFAULT_PAGE);

        foreach ((array) ($payload['messages'] ?? []) as $index => $turn) {
            if (! is_array($turn) || ! is_string($turn['from'] ?? null) || $turn['from'] === '') {
                continue;
            }

            $from = $turn['from'];
            $mid = (string) ($turn['mid'] ?? "sim-{$page}-{$from}-{$index}");
            // A comment thread is one person's comments on one post (MetaCommentNormaliser); a DM thread is the account and the sender.
            $post = (string) ($turn['post'] ?? 'sim-post-1');
            $threadRef = $this->channel === Channel::Comment ? "comment:{$page}:{$post}:{$from}" : "{$page}:{$from}";

            yield new InboundMessage(
                channel: $this->channel,
                platformAccountRef: $page,
                externalThreadRef: $threadRef,
                externalMessageRef: $mid,
                senderExternalId: $from,
                body: isset($turn['text']) ? (string) $turn['text'] : null,
                receivedAt: isset($turn['at']) ? CarbonImmutable::parse((string) $turn['at']) : CarbonImmutable::now(),
                digest: hash('sha256', json_encode($turn, JSON_UNESCAPED_UNICODE) ?: $mid),
                attachments: array_values(array_filter((array) ($turn['attachments'] ?? []), 'is_array')),
                metadata: array_filter(['simulated' => true, 'post_id' => $this->channel === Channel::Comment ? $post : null, 'sender_name' => $turn['name'] ?? null]),
            );
        }
    }

    public function send(OutboundMessage $message): DeliveryReceipt
    {
        $receipt = new DeliveryReceipt(DeliveryReceipt::SIMULATED, 'sim-'.count($this->sent), CarbonImmutable::now());
        $this->sent[] = ['message' => $message, 'receipt' => $receipt];

        return $receipt;
    }

    /** @return list<array{message: OutboundMessage, receipt: DeliveryReceipt}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
