<?php

namespace App\Conversation\Deliveries;

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\DeliveryWatermark;
use App\Models\MessageDelivery;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;

/**
 * The provider's own word on what happened to each message HQ sent (ADR-031, docs/72 §B, docs/88 A
 * point 2).
 *
 * `message_deliveries` holds one row per attempt. `ChannelSender` writes the first half (the request:
 * `sending`, then `accepted`, `failed` or `uncertain`); this class writes the second half, from the
 * provider's delivery webhooks: `sent`, `delivered`, `read`, `played` or `failed`. A message is real,
 * and `verified_at` is set, the first time the provider says `delivered` or later; never when the send
 * request returned 200.
 *
 * Rules:
 *  - A signal only ever moves a delivery FORWARD (sent, delivered, read, played). Webhooks arrive out of
 *    order; a late `sent` after `read` changes nothing.
 *  - `failed` is taken only before delivery. Meta does not fail a delivered message, and a late failure
 *    must not unverify one.
 *  - A signal for a provider id HQ never sent is not ours: nothing is written and null is returned. The
 *    webhook is shared by every sender on the account (the n8n bot, a person in Meta Business Suite).
 *  - Verification and failure are audit rows.
 */
final class DeliveryLedger
{
    /** Forward order. `failed`, `uncertain`, `sending` and `simulated` sit below all of it. */
    private const RANK = ['accepted' => 1, DeliveryStatus::SENT => 2, DeliveryStatus::DELIVERED => 3, DeliveryStatus::READ => 4, DeliveryStatus::PLAYED => 5];

    /** An attempt in any of these states is never followed by an automatic second attempt. */
    public const SETTLED = ['sending', DeliveryReceipt::UNCERTAIN, DeliveryReceipt::ACCEPTED, DeliveryReceipt::SIMULATED, DeliveryStatus::SENT, DeliveryStatus::DELIVERED, DeliveryStatus::READ, DeliveryStatus::PLAYED];

    public function __construct(private readonly Auditor $audit) {}

    public function apply(DeliveryStatus $signal): ?MessageDelivery
    {
        $delivery = MessageDelivery::query()->where('provider_ref', $signal->providerRef)->latest('id')->first();
        if ($delivery === null) {
            return null;
        }

        if ($signal->status === DeliveryStatus::FAILED) {
            return $this->fail($delivery, $signal);
        }

        return $this->promote($delivery, $signal->status, $signal->at);
    }

    /**
     * Messenger's watermark: every delivery on this Page-and-person thread sent at or before it.
     *
     * @return int how many deliveries moved
     */
    public function applyWatermark(DeliveryWatermark $watermark): int
    {
        $target = self::RANK[$watermark->status] ?? null;
        if ($target === null) {
            return 0;
        }

        $below = array_keys(array_filter(self::RANK, static fn (int $rank) => $rank < $target));
        $moved = 0;

        MessageDelivery::query()
            ->whereIn('status', $below)
            ->whereHas('message', fn ($m) => $m->where('channel', 'messenger')
                ->where('sent_at', '<=', $watermark->watermark->utc())
                ->whereHas('conversation', fn ($c) => $c->where('channel', 'messenger')->where('external_thread_ref', $watermark->threadRef())))
            ->orderBy('id')
            ->each(function (MessageDelivery $delivery) use ($watermark, &$moved) {
                $before = $delivery->status;
                $this->promote($delivery, $watermark->status, $watermark->watermark);
                $moved += $delivery->status !== $before ? 1 : 0;
            });

        return $moved;
    }

    /**
     * The reconciliation's answer for one attempt that never recorded its outcome (`sending` left by a
     * crash, `uncertain` after a timeout): the provider's word, read back by id. Forward-only like a
     * webhook signal, so an attempt a status webhook already moved on is left where it is; every
     * change is an audit row, because a person may have been waiting on this exact attempt.
     */
    public function settle(MessageDelivery $delivery, DeliveryStatus $answer): MessageDelivery
    {
        $before = ['status' => $delivery->status, 'error' => $delivery->error];

        $settled = $answer->status === DeliveryStatus::FAILED
            ? $this->fail($delivery, $answer)
            : $this->promote($delivery, $answer->status, $answer->at);

        if ($settled->status !== $before['status']) {
            $this->audit->recordSystem('message.delivery_reconciled', $settled, $before, ['status' => $settled->status, 'error' => $settled->error],
                "the provider answered [{$answer->status}] for [{$delivery->provider_ref}] when asked again");
        }

        return $settled;
    }

    /** What an existing attempt means to a caller asking to send again. */
    public function receiptFor(MessageDelivery $delivery): DeliveryReceipt
    {
        $at = CarbonImmutable::instance($delivery->updated_at ?? now());

        return match ($delivery->status) {
            'sending' => new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, $delivery->provider_ref, $at, 'an earlier attempt never recorded its outcome; it is settled by reconciliation or a person, never by sending again', 'replayed'),
            DeliveryReceipt::UNCERTAIN => new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, $delivery->provider_ref, $at, 'an earlier attempt is uncertain; it is settled by reconciliation or a person, never by sending again', 'replayed'),
            DeliveryReceipt::SIMULATED => new DeliveryReceipt(DeliveryReceipt::SIMULATED, $delivery->provider_ref, $at, null, 'replayed'),
            default => new DeliveryReceipt(DeliveryReceipt::ACCEPTED, $delivery->provider_ref, $at, null, 'replayed'),
        };
    }

    private function promote(MessageDelivery $delivery, string $status, CarbonImmutable $at): MessageDelivery
    {
        $target = self::RANK[$status] ?? 0;
        $current = self::RANK[$delivery->status] ?? 0;
        if ($target <= $current) {
            return $delivery;
        }

        $before = ['status' => $delivery->status, 'verified_at' => $delivery->verified_at?->toIso8601String()];
        $delivery->status = $status;
        $verifiedNow = $target >= self::RANK[DeliveryStatus::DELIVERED] && $delivery->verified_at === null;
        if ($verifiedNow) {
            $delivery->verified_at = $at->utc();
        }
        $delivery->save();

        if ($verifiedNow) {
            $this->audit->recordSystem('message.delivery_verified', $delivery, $before,
                ['status' => $delivery->status, 'verified_at' => $delivery->verified_at?->toIso8601String()],
                "the provider reported [{$status}] for [{$delivery->provider_ref}]");
        }

        return $delivery;
    }

    private function fail(MessageDelivery $delivery, DeliveryStatus $signal): MessageDelivery
    {
        if ((self::RANK[$delivery->status] ?? 0) >= self::RANK[DeliveryStatus::DELIVERED] || $delivery->status === DeliveryStatus::FAILED) {
            return $delivery;
        }

        $code = $signal->error['code'] ?? null;
        $title = $signal->error['title'] ?? 'no reason given';
        $before = ['status' => $delivery->status];
        $delivery->forceFill([
            'status' => DeliveryStatus::FAILED,
            'error' => mb_substr('provider reported failed'.($code === null ? '' : " (code {$code})").": {$title}", 0, 1000),
        ])->save();

        $this->audit->recordSystem('message.delivery_failed', $delivery, $before, ['status' => DeliveryStatus::FAILED, 'error' => $delivery->error],
            "the provider reported [failed] for [{$delivery->provider_ref}]");

        return $delivery;
    }
}
