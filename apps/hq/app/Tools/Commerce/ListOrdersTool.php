<?php

namespace App\Tools\Commerce;

use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\CommerceRegistry;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Store;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Live orders, read from the shop itself (docs/48 D.6).
 *
 * ⭐ **Why this exists beside `facts.query_orders`.** `facts_orders` is a read model and therefore a
 * copy with an age. `docs/48` I.2 is explicit that order status is never memory: it is looked up
 * every time. So aggregate questions go to the read model, which is cheap and can say what it
 * covers, and "what is the state of things right now" comes here, which costs a call to the shop.
 * An agent that used the read model for the second question would be confidently reporting
 * yesterday.
 *
 * ⛔ **Customer personal data is stripped before it leaves this tool.** The adapter returns names and
 * phone numbers because reconciliation needs them; a model prompt does not (V.6). What survives is
 * the order reference, its status, its money and its age. The phone is reduced to a boolean saying
 * whether the shop holds one, because "we cannot contact this customer" is an operational fact and
 * the number itself is not.
 */
final class ListOrdersTool implements Tool
{
    private const MAX = 100;

    public function __construct(private readonly CommerceRegistry $registry) {}

    public function key(): string
    {
        return 'commerce.list_orders';
    }

    /**
     * R2: it reads a system of record over the network, consuming the shop's quota and its admin
     * token. It writes nothing anywhere, which is what separates it from `commerce.reconcile_facts`.
     */
    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'List live shop orders',
            purpose: 'Reads recent orders directly from one shop. Live status, no personal data, nothing written.',
            riskTier: RiskTier::R2,
            permission: 'hq.tools.commerce.read',
            auditAction: 'tool.commerce_list_orders',
            scope: ToolScope::Store,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            scopeFrom: 'store:args.store_slug',
            cardinality: ToolCardinality::OnePerStore,
            credentials: [
                new CredentialRequirement(CommerceRegistry::ADMIN_PROVIDER, 'args.store_slug|store.slug'),
            ],
            idempotent: true,
            idempotencyKeyFrom: ['store_slug', 'hours'],
            timeoutSeconds: 60,
            outputSchema: $this->outputSchema(),
            // Reading the same window from the same shop twice inside a minute is provably wasted
            // work rather than merely similar work, and it is a network call to somebody else's box.
            replayWithinSeconds: 60,
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
            ],
            'required' => ['store_slug'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'store' => ['type' => 'string'],
            'orders' => ['type' => 'array'],
            'count' => ['type' => 'integer'],
            'since' => ['type' => 'string'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $slug = (string) ($payload['store_slug'] ?? '');
        $store = $slug === '' ? null : Store::where('slug', $slug)->first();

        if ($store === null) {
            return ToolResult::failed("Unknown store [{$slug}]: name a store HQ has an adapter for.");
        }

        $hours = (int) ($payload['hours'] ?? 24);
        $limit = min((int) ($payload['limit'] ?? 50), self::MAX);
        $since = CarbonImmutable::now('UTC')->subHours($hours);

        try {
            $orders = $this->registry->for($store)->listRecentOrders($since, $limit);
        } catch (Unsupported $e) {
            return ToolResult::failedBy($e); // a missing capability; a retry cannot help
        } catch (Unavailable $e) {
            throw $e; // the shop is down: let the gateway classify it and the caller decide
        }

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'ref' => $order->ref,
                'status' => $order->status,
                'payment_status' => $order->paymentStatus,
                'payment_method' => $order->paymentMethod,
                'total' => round($order->grandTotal, 2),
                'currency' => 'BDT',
                'placed_at' => $order->placedAt,
                'source' => $order->source,
                'line_count' => count($order->lines),
                // Not the number. Whether one exists.
                'has_customer_phone' => ($order->customerPhone ?? '') !== '',
            ];
        }

        return ToolResult::succeeded([
            'store' => $store->slug,
            'orders' => $rows,
            'count' => count($rows),
            'hours' => $hours,
            'since' => $since->setTimezone($timezone)->toIso8601String(),
            // Live, so its age is zero by definition, and saying so stops it being confused with a
            // snapshot in an answer that mixes both.
            'read_live_at' => CarbonImmutable::now($timezone)->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
