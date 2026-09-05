<?php

use App\Commerce\Adapters\LaravelShopAdapter;
use App\Commerce\Dto\DraftAddress;
use App\Commerce\Dto\DraftCustomer;
use App\Commerce\Dto\DraftLine;
use App\Commerce\Dto\IdempotencyKey;
use App\Commerce\Dto\OrderDraft;
use App\Commerce\Dto\OrderResult;
use App\Commerce\Dto\StoreRef;
use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\CommerceRegistry;
use App\Commerce\Support\ConnectorCallLogger;
use App\Models\ConnectorCall;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | Fixtures are REAL responses captured from laladia.com's public API on 5 Sep 2026
 | (tests/fixtures/shop). The assistant endpoints return the same resource shape.
 */

function fixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/fixtures/shop/{$name}.json")), true);
}

function adapter(?string $key = 'k', ?string $token = null): LaravelShopAdapter
{
    return new LaravelShopAdapter(new StoreRef(1, 'laladia-com', 'laladia', 'https://laladia.com', 2, 'LLD'), $key, $token, app(ConnectorCallLogger::class));
}

function draft(): OrderDraft
{
    return new OrderDraft(new DraftCustomer('Test Buyer', '01711000000'), new DraftAddress('House 1', 'Dhaka', 'Gulshan'), [new DraftLine(2, variantId: 39)], conversationRef: 'conv-1');
}

it('maps a real product payload into DTOs and derives availability from status and stock', function () {
    Http::fake(['laladia.com/api/v1/assistant/products/handcrafted-cane-sugar' => Http::response(fixture('product-detail'))]);
    $p = adapter()->getProduct('handcrafted-cane-sugar');

    expect($p->slug)->toBe('handcrafted-cane-sugar')->and($p->isActive)->toBeTrue()->and($p->categorySlug)->toBe('sweeteners')
        ->and($p->variants)->toHaveCount(2)->and($p->variants[0]->sku)->toBe('SUS-350GM-JAR')->and($p->variants[0]->finalPrice)->toBe(240.0)
        ->and($p->variants[0]->availableStock)->toBe(1000)->and($p->variant(39))->not->toBeNull();

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'k') && str_contains($r->url(), '/assistant/products/handcrafted-cane-sugar'));
    expect(ConnectorCall::where('connector', 'shop:laladia-com')->where('operation', 'getProduct')->where('status', 'succeeded')->count())->toBe(1);
});

it('derives availability: ok, status-based, stock-based and inactive', function () {
    $list = fixture('products-list');
    $list['data'][0]['variants'][0]['available_stock'] = 0;      // variant 39 out of stock
    $list['data'][1]['status'] = 'out_of_stock';                  // second product marked by status
    $list['meta']['last_page'] = 1;
    Http::fake(['laladia.com/api/v1/assistant/products*' => Http::response($list)]);
    $a = adapter();
    $v0 = $list['data'][0]['variants'][0]['id'];
    $v1 = $list['data'][0]['variants'][1]['id'];
    $v2 = $list['data'][1]['variants'][0]['id'];

    expect($a->getAvailability($v0)->reason)->toBe('stock_insufficient')->and($a->getAvailability($v0)->orderable)->toBeFalse()
        ->and($a->getAvailability($v1)->orderable)->toBeTrue()->and($a->getAvailability($v1)->reason)->toBe('ok')
        ->and($a->getAvailability($v2)->reason)->toBe('status:out_of_stock');
    expect(fn () => $a->getAvailability(999999))->toThrow(Refused::class);
});

it('builds the exact checkout payload the shop validates and marks the order with the conversation ref', function () {
    $payload = draft()->toShopPayload();
    expect($payload['items'])->toBe([['variant_id' => 39, 'quantity' => 2]])
        ->and($payload['customer_phone'])->toBe('01711000000')->and($payload['district'])->toBe('Dhaka')->and($payload['thana'])->toBe('Gulshan')
        ->and($payload['city'])->toBe('Dhaka')->and($payload['payment_method'])->toBe('cod')->and($payload['agree_terms'])->toBeTrue()
        ->and($payload['notes'])->toBe('[hq:conv-1]')->and($payload)->not->toHaveKey('customer_email');
});

it('previews through the shop and never computes prices itself', function () {
    Http::fake(['laladia.com/api/v1/checkout/preview' => Http::response(['success' => true, 'data' => ['subtotal' => 480, 'discount_total' => 0, 'shipping_cost' => 60, 'grand_total' => 540, 'lines' => [['name' => 'Cane Sugar 350gm', 'quantity' => 2, 'unit_price' => 240, 'total' => 480, 'variant_id' => 39]]]])]);
    $q = adapter()->previewOrder(draft());
    expect($q->total)->toBe(540.0)->and($q->shipping)->toBe(60.0)->and($q->lines[0]->variantId)->toBe(39);
});

it('creates an order with an Idempotency-Key and classifies confirmed, refused and uncertain outcomes', function () {
    $key = IdempotencyKey::for('conv-1', 'draft-hash');
    Http::fake([
        'laladia.com/api/v1/checkout' => Http::sequence()
            ->push(['success' => true, 'data' => ['order' => ['order_number' => 'LLD-20260905-ABC123', 'order_status' => 'pending', 'grand_total' => 540, 'items' => [['product_name' => 'Cane Sugar', 'qty' => 2, 'unit_price' => 240, 'total' => 480]]]]])
            ->push(['message' => 'The given data was invalid.', 'errors' => ['customer_phone' => ['bad']]], 422)
            ->push(['success' => false, 'message' => 'Insufficient stock for 350gm Jar'], 200)
            ->push('', 502),
    ]);
    $a = adapter();
    $r1 = $a->createOrder(draft(), $key);
    expect($r1->confirmed())->toBeTrue()->and($r1->order->ref)->toBe('LLD-20260905-ABC123')->and($r1->order->lines[0]->quantity)->toBe(2);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $key->value) && $r['checkout_token'] === $key->value && $r['items'][0]['variant_id'] === 39);

    expect($a->createOrder(draft(), $key)->state)->toBe(OrderResult::REFUSED);
    expect($a->createOrder(draft(), $key)->state)->toBe(OrderResult::REFUSED);
    $r4 = $a->createOrder(draft(), $key);
    expect($r4->state)->toBe(OrderResult::UNCERTAIN);
    expect(ConnectorCall::where('operation', 'createOrder')->where('idempotency_key', $key->value)->count())->toBe(4);
});

it('reconciles an uncertain create by finding the marked order through the admin API, or returns null without a token', function () {
    expect(adapter()->reconcileOrder(IdempotencyKey::generate(), draft()))->toBeNull();

    Http::fake(['laladia.com/api/v1/admin/orders*' => Http::response(['success' => true, 'data' => [
        ['order_number' => 'LLD-1', 'order_status' => 'pending', 'grand_total' => 540, 'placed_at' => now()->subMinutes(2)->toDateTimeString(), 'notes' => 'x [hq:conv-1]', 'items' => [['product_name' => 'Cane', 'qty' => 2, 'unit_price' => 240, 'total' => 480]]],
    ]])]);
    $found = adapter(token: 'admin-token')->reconcileOrder(IdempotencyKey::generate(), draft());
    expect($found?->ref)->toBe('LLD-1');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer admin-token'));
});

it('refuses admin operations without a token and maps 401 to Unsupported, 422 to ValidationFailed', function () {
    expect(fn () => adapter()->getOrder('LLD-1'))->toThrow(Unsupported::class);
    Http::fake(['laladia.com/api/v1/assistant/products/x' => Http::response(['message' => 'Unauthorized'], 401)]);
    expect(fn () => adapter('wrong')->getProduct('x'))->toThrow(Unsupported::class);
    Http::fake(['laladia.com/api/v1/checkout/preview' => Http::response(['message' => 'invalid', 'errors' => ['district' => ['required']]], 422)]);
    expect(fn () => adapter()->previewOrder(draft()))->toThrow(ValidationFailed::class);
});

it('resolves stores to adapters with credentials from integration_credentials, and has no adapter for woocommerce', function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_assistant_key', 'account_ref' => 'laladia-com', 'credential_type' => 'api_key', 'payload' => ['value' => 'secret-key'], 'status' => 'active']);
    $registry = app(CommerceRegistry::class);
    $adapter = $registry->for('laladia-com');
    expect($adapter->capabilities()->supports('preview'))->toBeTrue()->and($adapter->capabilities()->supports('order_status'))->toBeFalse();
    expect($registry->storesWithAdapters())->toEqualCanonicalizing(['laladia-com', 'bionic-garden']);
    expect(fn () => $registry->for(Store::where('slug', 'econic-store')->firstOrFail()))->toThrow(Unsupported::class);
});
