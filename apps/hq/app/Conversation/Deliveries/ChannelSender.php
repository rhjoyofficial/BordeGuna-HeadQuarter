<?php

namespace App\Conversation\Deliveries;

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Conversation\Channels\OutboundMessage;
use App\Models\ConversationMessage;
use App\Models\MessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The idempotent seam between a reply tool and a provider adapter (ADR-031, docs/88 A point 2).
 *
 * Meta's Send API and the WhatsApp Cloud API take no idempotency key, so exactly-once is HQ's to keep:
 *
 *  1. Under a row lock on the outbound message, read its latest attempt. If one is SETTLED (in flight,
 *     uncertain, accepted or confirmed later), return what it means and send nothing.
 *  2. Otherwise write a new attempt as `sending` and COMMIT before any request: if the process dies
 *     mid-call, the row says an attempt may have happened, and step 1 refuses to repeat it.
 *  3. Call the adapter once, and record its receipt on that attempt.
 *
 * Only a `failed` attempt allows another. A send is always of a message HQ has already recorded as
 * outbound: a reply that exists nowhere in HQ cannot be sent through here.
 *
 * ⛔ Nothing calls this outside tests: no reply tool reaches a provider while `PlatformRegistry` is
 * empty (gate P3-G4 wires it).
 */
final class ChannelSender
{
    public function __construct(private readonly DeliveryLedger $ledger) {}

    public function send(OutboundChannelAdapter $adapter, OutboundMessage $message): DeliveryReceipt
    {
        $row = ConversationMessage::query()->where('uuid', $message->messageUuid)->first();
        if ($row === null || $row->direction !== 'outbound') {
            return new DeliveryReceipt(DeliveryReceipt::FAILED, null, CarbonImmutable::now('UTC'),
                'HQ holds no outbound message with this id; a send is always of a message HQ has recorded', 'invalid_request');
        }

        /** @var array{0: ?MessageDelivery, 1: ?MessageDelivery} $claimed */
        $claimed = DB::transaction(function () use ($row) {
            ConversationMessage::query()->whereKey($row->id)->lockForUpdate()->first();
            $latest = MessageDelivery::query()->where('message_id', $row->id)->orderByDesc('attempt')->first();
            if ($latest !== null && in_array($latest->status, DeliveryLedger::SETTLED, true)) {
                return [null, $latest];
            }

            return [MessageDelivery::query()->create([
                'message_id' => $row->id,
                'attempt' => ($latest?->attempt ?? 0) + 1,
                'status' => 'sending',
            ]), null];
        });

        [$attempt, $prior] = $claimed;
        if ($prior !== null) {
            return $this->ledger->receiptFor($prior);
        }

        try {
            $receipt = $adapter->send($message);
        } catch (Throwable $e) {
            $receipt = new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, CarbonImmutable::now('UTC'),
                'the adapter threw mid-send: '.mb_substr($e->getMessage(), 0, 300));
        }

        $attempt->forceFill([
            'status' => $receipt->status,
            'provider_ref' => $receipt->providerRef,
            'error' => $receipt->error === null ? null : mb_substr($receipt->error, 0, 1000),
        ])->save();

        return $receipt;
    }
}
