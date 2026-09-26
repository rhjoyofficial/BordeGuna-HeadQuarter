<?php

namespace Tests\Support\Erp;

use App\Gateway\Approval\CanonicalPayload;
use App\Identity\PhoneCanonical;
use ArrayObject;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A simulated ERP with the real endpoints' behaviour for HQ's order tools (docs/83 C.3, docs/88 A
 * point 3). The ERP's own tests prove the endpoints; this reproduces what they proved, with the
 * fixtures the ERP wrote as templates and this request's values put in:
 *
 *  - drafts: one per key, a replay for the same request, 409 for another, the read-back by key;
 *  - orders: one per key and one per draft, a replay, 409 for another request or a second key on the
 *    same draft, 404 for an unknown draft, 422 when the ERP now prices the draft differently; the
 *    read-back by reference (shop reference or order number) and by HQ's key; only orders HQ placed;
 *  - the quote: priced from a fixed price list, 422 for an unknown SKU;
 *  - the cancel: only an open order with nothing delivered, once per key, a replay for the same
 *    request, 409 already_cancelled / has_deliveries / idempotency_key_reused, 404 for an order HQ
 *    did not place.
 *
 * Every option makes the simulation misbehave in ONE named way, so a test proves what HQ does about
 * exactly that.
 *
 * @phpstan-type Opts array{mismatch?: bool, postTimesOut?: bool, readBackFails?: bool, readBackTampered?: bool,
 *     draftRepriced?: bool, orderPostTimesOut?: bool, orderReadBackTampered?: bool, cancelPostTimesOut?: bool,
 *     cancelReadBackStale?: bool}
 */
final class SimulatedErp
{
    public const BASE = 'https://erp.take-order.test';

    public const BUSINESS = 1;

    private const PRICES = ['HON-500' => '850.0000', 'GHE-250' => '650.5000'];

    /** @param Opts $opts */
    public static function fake(array $opts = []): ArrayObject
    {
        $state = new ArrayObject(['drafts' => [], 'orders' => [], 'posts' => 0, 'gets' => 0, 'orderPosts' => 0, 'orderGets' => 0, 'quotes' => 0, 'cancels' => 0]);
        $drafts = self::BASE.'/api/hq/v1/'.self::BUSINESS.'/sales-order-drafts';
        $orders = self::BASE.'/api/hq/v1/'.self::BUSINESS.'/sales-orders';

        Http::fake(function (Request $r) use ($state, $opts, $drafts, $orders) {
            $url = $r->url();
            $method = $r->method();

            if ($method === 'POST' && $url === $drafts) {
                return self::postDraft($state, $opts, $r->data());
            }
            if ($method === 'GET' && str_starts_with($url, $drafts.'/')) {
                return self::getDraft($state, $opts, rawurldecode(substr($url, strlen($drafts) + 1)));
            }
            if ($method === 'POST' && $url === $orders.'/quote') {
                return self::quote($state, $r->data());
            }
            if ($method === 'POST' && $url === $orders) {
                return self::postOrder($state, $opts, $r->data());
            }
            if ($method === 'GET' && str_starts_with($url, $orders.'/by-key/')) {
                return self::getOrder($state, $opts, fn (array $o) => $o['data']['hq']['idempotency_key'] === rawurldecode(substr($url, strlen($orders.'/by-key/'))));
            }
            if ($method === 'POST' && str_starts_with($url, $orders.'/') && str_ends_with($url, '/cancel')) {
                $reference = rawurldecode(substr($url, strlen($orders) + 1, -strlen('/cancel')));

                return self::cancel($state, $opts, $reference, $r->data());
            }
            if ($method === 'GET' && str_starts_with($url, $orders.'/')) {
                $reference = rawurldecode(substr($url, strlen($orders) + 1));

                return self::getOrder($state, $opts, fn (array $o) => in_array($reference, [$o['data']['shop_reference'], $o['data']['order_number']], true));
            }

            return Http::response(['error' => ['code' => 'unexpected', 'message' => 'not simulated: '.$method.' '.$url]], 500);
        });

        return $state;
    }

    private static function meta(string $contract): array
    {
        return ['business_id' => self::BUSINESS, 'contract' => $contract, 'as_of' => '2026-09-21T10:00:00+06:00'];
    }

    private static function fixture(string $folder, string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/fixtures/erp/{$folder}/{$name}.json")), true);
    }

    // ── drafts ─────────────────────────────────────────────────────────────────────────────────

    private static function postDraft(ArrayObject $state, array $opts, array $body): PromiseInterface
    {
        $state['posts']++;
        $key = $body['idempotency_key'];
        $digest = hash('sha256', CanonicalPayload::encode($body));
        $drafts = $state['drafts'];
        if (isset($drafts[$key])) {
            return $drafts[$key]['digest'] === $digest
                ? Http::response(['data' => ['replayed' => true] + $drafts[$key]['data'], 'meta' => self::meta('hq-sales-order-drafts.v1')], 200)
                : Http::response(['error' => ['code' => 'idempotency_key_reused', 'message' => 'This idempotency key was already used for a different draft. Nothing was written.']], 409);
        }
        if ($opts['mismatch'] ?? false) {
            return Http::response(['error' => ['code' => 'quote_mismatch', 'message' => 'Figures in the proposal no longer match the ERP\'s own. Nothing was written; quote again.'],
                'errors' => ['lines.0.unit_price' => ['Proposed 850.00, the ERP\'s price is 900.00.']]], 422);
        }
        $data = self::fixture('sales-order-drafts', 'created')['data'];
        $data['id'] = count($drafts) + 1;
        $data['idempotency_key'] = $key;
        $data['hq_proposal_fingerprint'] = $body['hq_reference']['proposal_fingerprint'];
        $data['hq_reference'] = ['proposal_fingerprint' => $body['hq_reference']['proposal_fingerprint'], 'approval_uuid' => null, 'tool_call_id' => null, 'conversation_uuid' => $body['hq_reference']['conversation_uuid'] ?? null];
        $data['store'] = ['key' => $body['store_key']];
        $data['recipient']['phone_normalized'] = PhoneCanonical::normalize($body['destination']['phone']);
        $data['destination']['district'] = $body['destination']['district'];
        $data['destination']['thana'] = $body['destination']['thana'];
        $data['lines'] = array_map(fn ($l) => ['sku' => $l['sku'], 'product_id' => 1, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'].'00',
            'line_total' => number_format($l['quantity'] * (float) $l['unit_price'], 2, '.', ''), 'price_note' => 'Price for group [RETAIL]', 'made_to_order' => false], $body['lines']);
        $data['goods_total'] = $body['proposed']['goods_total'];
        $data['delivery_charge'] = $body['proposed']['delivery_charge'];
        $data['agreed_total'] = $body['proposed']['agreed_total'];
        unset($data['replayed']);
        $drafts[$key] = ['digest' => $digest, 'data' => $data];
        $state['drafts'] = $drafts;

        if (($opts['postTimesOut'] ?? false) && $state['posts'] === 1) {
            throw new ConnectionException('timed out after the ERP wrote it');
        }

        return Http::response(['data' => $data + ['replayed' => false], 'meta' => self::meta('hq-sales-order-drafts.v1')], 201);
    }

    private static function getDraft(ArrayObject $state, array $opts, string $key): PromiseInterface
    {
        $state['gets']++;
        if ($opts['readBackFails'] ?? false) {
            throw new ConnectionException('read-back timed out');
        }
        $found = $state['drafts'][$key] ?? null;
        if ($found === null) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'No draft with that key in this business.']], 404);
        }
        $data = $found['data'];
        if ($opts['readBackTampered'] ?? false) {
            $data['lines'][0]['quantity'] = 3;
        }

        return Http::response(['data' => $data, 'meta' => self::meta('hq-sales-order-drafts.v1')]);
    }

    // ── the quote ──────────────────────────────────────────────────────────────────────────────

    private static function quote(ArrayObject $state, array $body): PromiseInterface
    {
        $state['quotes']++;
        $lines = [];
        $goods = 0.0;
        foreach (array_values($body['lines']) as $i => $line) {
            $price = self::PRICES[$line['sku']] ?? null;
            if ($price === null) {
                return Http::response(['error' => ['code' => 'refused', 'message' => 'The ERP would not take this order as it stands.'],
                    'errors' => ["lines.{$i}.sku" => ["No product with SKU {$line['sku']} in this business."]]], 422);
            }
            $total = $line['quantity'] * (float) $price;
            $goods += $total;
            $lines[] = ['sku' => $line['sku'], 'product_id' => $i + 1, 'quantity' => $line['quantity'], 'unit_price' => $price,
                'line_total' => number_format($total, 2, '.', ''), 'price_note' => 'Price for group [RETAIL]', 'sellable' => true, 'made_to_order' => $line['sku'] === 'GHE-250'];
        }
        $data = self::fixture('sales-orders', 'quote')['data'];
        $data['store'] = ['key' => $body['store_key']];
        $data['lines'] = $lines;
        $data['goods_total'] = number_format($goods, 2, '.', '');
        $data['agreed_total'] = number_format($goods + 120, 2, '.', '');

        return Http::response(['data' => $data, 'meta' => self::meta('hq-sales-orders.v1')]);
    }

    // ── orders ─────────────────────────────────────────────────────────────────────────────────

    private static function postOrder(ArrayObject $state, array $opts, array $body): PromiseInterface
    {
        $state['orderPosts']++;
        $key = $body['idempotency_key'];
        $digest = hash('sha256', CanonicalPayload::encode($body));
        $orders = $state['orders'];
        if (isset($orders[$key])) {
            return $orders[$key]['digest'] === $digest
                ? Http::response(['data' => ['replayed' => true] + $orders[$key]['data'], 'meta' => self::meta('hq-sales-orders.v1')], 200)
                : Http::response(['error' => ['code' => 'idempotency_key_reused', 'message' => 'This idempotency key was already used for a different order. Nothing was written.']], 409);
        }
        $draft = $state['drafts'][$body['draft_key']] ?? null;
        if ($draft === null) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'No draft with that key in this business.']], 404);
        }
        foreach ($orders as $existing) {
            if ($existing['data']['hq']['draft_key'] === $body['draft_key']) {
                return Http::response(['error' => ['code' => 'draft_already_confirmed', 'message' => "This draft is already order {$existing['data']['order_number']}; a draft becomes one order, once. Nothing was written."]], 409);
            }
        }
        if ($opts['draftRepriced'] ?? false) {
            return Http::response(self::fixture('sales-orders', 'draft-repriced'), 422);
        }
        $d = $draft['data'];
        $n = count($orders) + 1;
        $data = self::fixture('sales-orders', 'created')['data'];
        $data['id'] = $n;
        $data['order_number'] = sprintf('SO-2026-27-%04d', $n);
        $data['shop_reference'] = sprintf('LLD1-20260921-SIM%05d', $n);
        $data['store'] = $d['store'];
        $data['lines'] = array_map(fn ($l) => ['sku' => $l['sku'], 'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'quantity_delivered' => '0.0000',
            'unit_price' => $l['unit_price'], 'line_discount' => '0.00', 'price_note' => $l['price_note']], $d['lines']);
        $data['payment_method'] = $d['payment_method'];
        $data['goods_total'] = $d['goods_total'];
        $data['delivery_charge'] = $d['delivery_charge'];
        $data['agreed_total'] = $d['agreed_total'];
        $data['hq'] = ['draft_key' => $body['draft_key'], 'idempotency_key' => $key, 'proposal_fingerprint' => $d['hq_proposal_fingerprint'], 'approval_uuid' => $body['hq_reference']['approval_uuid'] ?? null];
        unset($data['replayed']);
        $orders[$key] = ['digest' => $digest, 'data' => $data];
        $state['orders'] = $orders;

        if (($opts['orderPostTimesOut'] ?? false) && $state['orderPosts'] === 1) {
            throw new ConnectionException('timed out after the ERP wrote the order');
        }

        return Http::response(['data' => $data + ['replayed' => false], 'meta' => self::meta('hq-sales-orders.v1')], 201);
    }

    /** @param  callable(array): bool  $matches */
    private static function getOrder(ArrayObject $state, array $opts, callable $matches): PromiseInterface
    {
        $state['orderGets']++;
        foreach ($state['orders'] as $order) {
            if ($matches($order)) {
                $data = $order['data'];
                if ($opts['orderReadBackTampered'] ?? false) {
                    $data['lines'][0]['quantity'] = 3;
                }
                if (($opts['cancelReadBackStale'] ?? false) && $data['status'] === 'cancelled') {
                    $data['status'] = 'open';
                    $data['cancellation'] = ['requested' => false];
                }

                return Http::response(['data' => $data, 'meta' => self::meta('hq-sales-orders.v1')]);
            }
        }

        return Http::response(['error' => ['code' => 'not_found', 'message' => 'No order HQ placed with that reference in this business.']], 404);
    }

    private static function cancel(ArrayObject $state, array $opts, string $reference, array $body): PromiseInterface
    {
        $state['cancels']++;
        $key = $body['idempotency_key'];
        $digest = hash('sha256', CanonicalPayload::encode(['reference' => $reference] + $body));
        $orders = $state['orders'];
        foreach ($orders as $orderKey => $order) {
            $data = $order['data'];
            if (($data['cancellation']['idempotency_key'] ?? null) === $key) {
                return $order['cancelDigest'] === $digest
                    ? Http::response(['data' => $data + ['replayed' => true], 'meta' => self::meta('hq-sales-orders.v1')])
                    : Http::response(['error' => ['code' => 'idempotency_key_reused', 'message' => 'This idempotency key was already used for a different cancellation. Nothing was written.']], 409);
            }
        }
        foreach ($orders as $orderKey => $order) {
            $data = $order['data'];
            if (! in_array($reference, [$data['shop_reference'], $data['order_number']], true)) {
                continue;
            }
            if ($data['status'] === 'cancelled') {
                return Http::response(['error' => ['code' => 'already_cancelled', 'message' => "Order {$data['order_number']} is already cancelled. Nothing was written."]], 409);
            }
            if ($data['status'] !== 'open') {
                return Http::response(['error' => ['code' => 'has_deliveries', 'message' => "Sales order {$data['order_number']} has deliveries against it. It cannot be cancelled; a person in the ERP closes it short or records a return."]], 409);
            }
            $data['status'] = 'cancelled';
            $data['cancellation'] = ['requested' => true, 'idempotency_key' => $key, 'reason' => $body['reason']];
            $data['next_step'] = 'None. The order is cancelled.';
            $orders[$orderKey] = ['digest' => $order['digest'], 'data' => $data, 'cancelDigest' => $digest];
            $state['orders'] = $orders;

            if (($opts['cancelPostTimesOut'] ?? false) && $state['cancels'] === 1) {
                throw new ConnectionException('timed out after the ERP cancelled it');
            }

            return Http::response(['data' => $data + ['replayed' => false], 'meta' => self::meta('hq-sales-orders.v1')]);
        }

        return Http::response(['error' => ['code' => 'not_found', 'message' => 'No order HQ placed with that reference in this business.']], 404);
    }

    /** Marks an order in the simulation as partly delivered, the way a delivery in the ERP would. */
    public static function deliver(ArrayObject $state, string $reference): void
    {
        $orders = $state['orders'];
        foreach ($orders as $key => $order) {
            if (in_array($reference, [$order['data']['shop_reference'], $order['data']['order_number']], true)) {
                $orders[$key]['data']['status'] = 'partially_delivered';
                $orders[$key]['data']['lines'][0]['quantity_delivered'] = '1.0000';
            }
        }
        $state['orders'] = $orders;
    }
}
