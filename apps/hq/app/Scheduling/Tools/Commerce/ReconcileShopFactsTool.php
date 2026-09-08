<?php

namespace App\Scheduling\Tools\Commerce;

use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Facts\FactsReconciler;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Store;
use App\Scheduling\Tools\Tool;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Runs the order reconciliation safety net for one store on the durable scheduler, so that catching
 * a lost webhook stops depending on a person remembering to type a command.
 *
 * Idempotent by construction: reconciliation upserts on (store, order_ref), so running it twice
 * over the same window inserts nothing the second time. That is why reconcile() has nothing to look
 * up - there is no external effect to recover, and a retry is simply another read of the shop.
 *
 * The window is a payload key rather than a constant because the right size is a judgement about
 * how late a webhook can arrive, not a property of the code: 48 hours gives a full day of overlap
 * on a nightly run without asking the shop for its whole history every night.
 */
final class ReconcileShopFactsTool implements Tool
{
    private const DEFAULT_HOURS = 48;

    public function __construct(private readonly FactsReconciler $reconciler) {}

    public function key(): string
    {
        return 'commerce.reconcile_facts';
    }

    /**
     * Tier R3: it reads the shop (R2) and then upserts facts_orders, customers and identities,
     * which is HQ state only. Nothing at the shop changes, which is exactly why this is R3 and not
     * R4 - the effect is entirely inside HQ and entirely reversible here.
     *
     * The permission is `hq.tools.commerce.read` rather than `.write` because the write is to HQ's
     * own read model; `commerce.write` is reserved for a tool that changes something AT the shop,
     * and none is enabled in Phase 2.
     */
    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Reconcile shop order facts',
            purpose: 'Re-reads a window of shop orders and fills any fact a lost webhook never created.',
            riskTier: RiskTier::R3,
            permission: 'hq.tools.commerce.read',
            auditAction: 'tool.commerce_reconcile_facts',
            scope: ToolScope::Store,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            credentials: [
                new CredentialRequirement('shop_admin_token', 'args.store_slug|store.slug'),
            ],
            idempotent: true,
            idempotencyKeyFrom: ['store_slug', 'hours'],
            timeoutSeconds: 120,
            outputSchema: $this->outputSchema(),
        );
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'store' => ['type' => 'string'],
            'hours' => ['type' => 'integer'],
            'since' => ['type' => 'string'],
            'checked' => ['type' => 'integer'],
            'inserted' => ['type' => 'integer'],
        ]];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720],
            ],
            'required' => ['store_slug'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $slug = (string) ($payload['store_slug'] ?? '');
        $store = $slug === '' ? null : Store::where('slug', $slug)->first();
        if (! $store) {
            // A missing store is a configuration mistake, not a transient one: fail rather than
            // retry, and name the slug that was asked for so the job's history says why.
            return ToolResult::failed("Unknown store [{$slug}]: pass store_slug of a store HQ has an adapter for.");
        }

        $hours = (int) ($payload['hours'] ?? self::DEFAULT_HOURS);
        $since = CarbonImmutable::now('UTC')->subHours($hours);

        try {
            $result = $this->reconciler->reconcileStore($store, $since);
        } catch (Unsupported $e) {
            return ToolResult::failed($e->getMessage()); // a missing capability; retrying will not help
        } catch (Unavailable $e) {
            throw $e; // the shop is down or slow: let the scheduler retry with backoff
        }

        if ($result['skipped']) {
            return ToolResult::failed("[{$store->slug}] {$result['reason']}");
        }

        return ToolResult::succeeded([
            'store' => $store->slug,
            'hours' => $hours,
            'since' => $since->toIso8601String(),
            'checked' => $result['checked'],
            'inserted' => $result['inserted'],
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // reading the shop and upserting facts is safe to repeat
    }
}
