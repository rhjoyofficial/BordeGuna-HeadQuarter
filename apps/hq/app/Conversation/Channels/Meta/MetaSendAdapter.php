<?php

namespace App\Conversation\Channels\Meta;

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Conversation\Channels\OutboundMessage;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Platform\Capabilities;
use App\Platform\Contracts\MessagingChannel;
use Carbon\CarbonImmutable;

/**
 * What the three Meta send adapters share (docs/88 A point 2): the checks that refuse a message before
 * any request, and the one Graph call.
 *
 * ⛔ An adapter is reached only from a tool the gateway has already passed (consent, policy,
 * permission, autonomy, the outbound policy, the capability resolver), and none is registered in
 * `PlatformRegistry`, so today it is reached only from its tests. It sends exactly what it is handed:
 * it never rewrites, shortens or translates a reply, and a message it cannot carry is refused whole.
 */
abstract class MetaSendAdapter implements MessagingChannel, OutboundChannelAdapter
{
    public function __construct(protected readonly MetaGraphClient $graph) {}

    /** The vault provider that holds this account's send token. */
    abstract public function tokenProvider(): string;

    /** The longest reply the channel accepts, measured as the channel measures it. */
    abstract protected function fits(string $body): bool;

    abstract protected function limitDescription(): string;

    /** @return array{0: string, 1: array<string, mixed>} the Graph path and the request body */
    abstract protected function request(OutboundMessage $message): array;

    /** Reads the provider's message id out of a successful answer. */
    abstract protected function messageId(array $json): ?string;

    protected function supportsTemplates(): bool
    {
        return false;
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([MessagingChannel::SEND_SERVICE, MessagingChannel::RECEIVE]);
    }

    /** Meta's App Review gates sending on every one of these channels (docs/70 C2, C3). */
    public function requiresApproval(): array
    {
        return [MessagingChannel::SEND_SERVICE];
    }

    public function send(OutboundMessage $message): DeliveryReceipt
    {
        $refusal = $this->refusal($message);
        if ($refusal !== null) {
            return new DeliveryReceipt(DeliveryReceipt::FAILED, null, CarbonImmutable::now('UTC'), $refusal->describe(), $refusal->category, false);
        }

        [$path, $body] = $this->request($message);

        return $this->graph->send($path, $body, $this->tokenProvider(), $message->platformAccountRef, $this->platform(), fn (array $json) => $this->messageId($json));
    }

    /**
     * What Meta holds for a message id HQ recorded but never saw the outcome of (docs/95 §5 "Delivery").
     *
     * Graph serves a sent message as a node by its id (`GET /{message-id}?fields=id,created_time`,
     * with the Page token for Messenger and the Instagram token for Instagram DMs): a 2xx that names
     * the id means Meta accepted and holds the message, so the attempt is `accepted`; Meta's "object
     * with ID does not exist" (code 100) means the send never took, so it is `failed`. Anything else
     * (no credential open, transport, another error) is null: HQ does not know, and says so.
     *
     * ⛔ A read, never a resend: nothing here can reach the Send API.
     */
    public function readDelivery(string $providerRef, string $platformAccountRef): ?DeliveryStatus
    {
        if ($providerRef === '') {
            return null;
        }

        $answer = $this->graph->read($providerRef, ['fields' => 'id,created_time'], $this->tokenProvider(), $platformAccountRef);
        $now = CarbonImmutable::now('UTC');

        if ($answer['status'] === null) {
            return null;
        }
        if ($answer['status'] >= 200 && $answer['status'] < 300) {
            return ($answer['json']['id'] ?? null) === $providerRef
                ? new DeliveryStatus($this->channel(), $providerRef, DeliveryStatus::ACCEPTED, $now)
                : null;
        }

        $error = $answer['json']['error'] ?? [];
        if ((int) ($error['code'] ?? 0) === 100 || $answer['status'] === 404) {
            return new DeliveryStatus($this->channel(), $providerRef, DeliveryStatus::FAILED, $now, null, [
                'code' => $error['code'] ?? $answer['status'],
                'title' => 'Meta holds no message with this id: '.mb_substr((string) ($error['message'] ?? 'not found'), 0, 200),
            ]);
        }

        return null;
    }

    public function readCredentials(string $platformAccountRef): array
    {
        return [
            [$this->tokenProvider(), $platformAccountRef],
            [MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF],
        ];
    }

    private function refusal(OutboundMessage $message): ?MetaSendError
    {
        if ($message->channel !== $this->channel()) {
            return MetaSendError::local(MetaSendError::UNSUPPORTED, "a {$message->channel->value} message was handed to the {$this->channel()->value} adapter");
        }
        if ($message->platformAccountRef === '' || $message->recipientExternalId === '') {
            return MetaSendError::local(MetaSendError::INVALID_REQUEST, 'the message names no sending account or no recipient');
        }
        if ($message->template !== null && ! $this->supportsTemplates()) {
            return MetaSendError::local(MetaSendError::UNSUPPORTED, "{$this->channel()->value} has no message templates; a template is a WhatsApp message");
        }
        if ($message->template === null) {
            if (trim($message->body) === '') {
                return MetaSendError::local(MetaSendError::INVALID_REQUEST, 'the reply is empty');
            }
            if (! $this->fits($message->body)) {
                return MetaSendError::local(MetaSendError::INVALID_REQUEST, "the reply is longer than {$this->channel()->value} accepts ({$this->limitDescription()}); it is refused, never shortened");
            }
        }

        return null;
    }
}
