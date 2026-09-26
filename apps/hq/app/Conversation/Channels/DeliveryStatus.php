<?php

namespace App\Conversation\Channels;

use App\Gateway\Descriptor\Channel;
use Carbon\CarbonImmutable;

/**
 * What the provider later said happened to one message HQ sent (ADR-031, docs/72 §B).
 *
 * The second half of a delivery: `DeliveryReceipt` is the provider accepting a request, this is the
 * provider's own signal afterwards. WhatsApp sends one per message and step (`sent`, `delivered`,
 * `read`, `played` for a voice note, `failed`); Messenger names delivered messages by id. A message is
 * real when one of these says `delivered` or later, never when the send request returned 200.
 *
 * ⛔ Pure data from a webhook the controller has already verified. It names a provider message id and
 * nothing else about the message; whether HQ sent that id is the ledger's question.
 */
final readonly class DeliveryStatus
{
    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const PLAYED = 'played';

    public const FAILED = 'failed';

    public const ALL = [self::SENT, self::DELIVERED, self::READ, self::PLAYED, self::FAILED];

    /**
     * @param  array{code?: int|string|null, title?: string|null, details?: string|null}|null  $error
     */
    public function __construct(
        public Channel $channel,
        public string $providerRef,
        public string $status,
        public CarbonImmutable $at,
        public ?string $recipientExternalId = null,
        public ?array $error = null,
    ) {}
}
