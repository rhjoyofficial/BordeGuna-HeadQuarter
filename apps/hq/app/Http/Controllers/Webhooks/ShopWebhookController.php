<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Support\Events\EventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbox for shop webhooks (docs/35 section G). POST /webhooks/shop/{store}.
 *
 * Verifies `X-Shop-Signature` (HMAC-SHA256 of the raw body with the per-store secret held in
 * integration_credentials), rejects stale timestamps when the shop sends `X-Shop-Timestamp`,
 * dedupes on `X-Shop-Delivery` (or a content hash for the legacy envelope), writes ONE events row
 * and answers 202. Processing happens asynchronously in hq:events:process. The store is taken
 * from the URL, never from the payload (two shops share one payment merchant).
 */
final class ShopWebhookController extends Controller
{
    public const SIGNATURE_HEADER = 'X-Shop-Signature';

    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    /** The six events the LaLaDia platform emits today (verified in its listeners, 5 Sep 2026) plus the two Phase 1 additions. */
    public const ACCEPTED_EVENTS = ['order.created', 'order.updated', 'order.status_changed', 'order.payment_updated', 'shipment.status_updated', 'customer.registered', 'coupon.expired', 'inventory.low', 'payment.completed', 'shipment.delivered'];

    public function __construct(private readonly EventRecorder $events) {}

    public function __invoke(Request $request, string $store): JsonResponse
    {
        $storeModel = Store::where('slug', $store)->where('is_active', true)->first();
        if (! $storeModel) {
            return response()->json(['error' => 'unknown store'], 404);
        }

        $secret = IntegrationCredential::query()->where('provider', 'shop_webhook_secret')->where('account_ref', $store)->where('status', 'active')->first()?->payload['value'] ?? null;
        if ($secret === null) {
            Log::warning('shop webhook received but no secret configured', ['store' => $store]);

            return response()->json(['error' => 'webhook not configured for this store'], 503);
        }

        $raw = $request->getContent();
        $given = (string) $request->header(self::SIGNATURE_HEADER, '');
        $expected = hash_hmac('sha256', $raw, $secret);
        if ($given === '' || ! hash_equals($expected, $given)) {
            Log::warning('shop webhook signature mismatch', ['store' => $store, 'ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $timestamp = $request->header('X-Shop-Timestamp');
        if ($timestamp !== null) {
            $sent = is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : CarbonImmutable::parse($timestamp);
            if (abs($sent->diffInSeconds(now(), false)) > self::TIMESTAMP_TOLERANCE_SECONDS) {
                return response()->json(['error' => 'stale timestamp'], 401);
            }
        }

        $body = json_decode($raw, true);
        if (! is_array($body) || empty($body['event']) || ! array_key_exists('data', $body)) {
            return response()->json(['error' => 'malformed envelope: expected {event, data}'], 422);
        }
        $type = (string) $body['event'];
        if (! in_array($type, self::ACCEPTED_EVENTS, true)) {
            return response()->json(['error' => "unknown event type {$type}"], 422);
        }

        $deliveryId = $request->header('X-Shop-Delivery') ?? ($body['id'] ?? null);
        $data = is_array($body['data']) ? $body['data'] : ['value' => $body['data']];
        $aggregateId = $data['order_number'] ?? $data['order']['order_number'] ?? $data['email'] ?? $data['phone'] ?? $data['code'] ?? null;
        $dedupe = $deliveryId
            ? "shop:{$store}:{$deliveryId}"
            : 'shop:'.$store.':'.$type.':'.hash('sha256', $raw); // legacy envelope: the body itself is the identity

        $event = $this->events->record(
            type: $type,
            payload: $data + ['_store' => $store, '_schema_version' => (int) ($body['schema_version'] ?? 1)],
            source: "shop:{$store}",
            direction: 'inbound',
            aggregateType: str_starts_with($type, 'order.') || str_starts_with($type, 'payment.') || str_starts_with($type, 'shipment.') ? 'order' : explode('.', $type)[0],
            aggregateId: $aggregateId !== null ? (string) $aggregateId : null,
            businessId: $storeModel->business_id,
            dedupeKey: $dedupe,
            occurredAt: isset($body['occurred_at']) ? CarbonImmutable::parse($body['occurred_at']) : now(),
            schemaVersion: (int) ($body['schema_version'] ?? 1),
        );

        return response()->json(['accepted' => true, 'event_id' => $event->event_id, 'duplicate' => ! $event->wasRecentlyCreated], 202);
    }
}
