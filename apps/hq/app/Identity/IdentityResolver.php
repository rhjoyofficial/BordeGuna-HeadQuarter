<?php

namespace App\Identity;

use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMerge;
use App\Models\CustomerMergeCandidate;
use App\Models\Store;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Unified customer identity (docs/14, docs/35 section H). Deterministic rules only:
 *   1. canonical phone -> confidence 1.0, auto-link
 *   2. lower-cased email -> 0.9, auto-link when no phone conflict
 *   3. an already-linked channel id -> 1.0
 *   4. name + district within 30 days -> 0.6, queued as a merge CANDIDATE for a human, never merged
 * Phone is never unique-constrained (one household, one number). Idempotent: resolving the same
 * order twice returns the same customer and adds no duplicate identities.
 */
final class IdentityResolver
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, district?: ?string, order_ref?: ?string, shop_user_id?: int|string|null, placed_at?: ?string}  $facts
     */
    public function resolve(Store $store, array $facts): Customer
    {
        $phone = PhoneCanonical::normalize($facts['phone'] ?? null);
        $email = isset($facts['email']) && $facts['email'] !== '' ? strtolower(trim((string) $facts['email'])) : null;
        $businessId = $store->business_id;

        return DB::transaction(function () use ($store, $facts, $phone, $email, $businessId) {
            $customer = null;

            if (isset($facts['order_ref'])) {
                $customer = $this->byIdentity('shop_order', (string) $facts['order_ref'], $store->id);
            }
            if (! $customer && isset($facts['shop_user_id'])) {
                $customer = $this->byIdentity('shop_user', (string) $facts['shop_user_id'], $store->id);
            }
            if (! $customer && $phone) {
                $customer = $this->byIdentity('phone', $phone, null)
                    ?? Customer::where('business_id', $businessId)->where('primary_phone_canonical', $phone)->whereNull('merged_into_id')->orderBy('id')->first();
            }
            if (! $customer && $email) {
                $customer = $this->byIdentity('email', $email, null)
                    ?? Customer::where('business_id', $businessId)->where('email', $email)->whereNull('merged_into_id')->orderBy('id')->first();
            }

            $created = false;
            if (! $customer) {
                $customer = Customer::create([
                    'business_id' => $businessId,
                    'primary_phone_canonical' => $phone,
                    'name' => $facts['name'] ?? null,
                    'email' => $email,
                    'first_seen_at' => $facts['placed_at'] ?? now(),
                    'last_seen_at' => $facts['placed_at'] ?? now(),
                    'attributes' => [],
                ]);
                $created = true;
            } else {
                $customer = $customer->canonical();
                $attrs = $customer->attributes ?? [];
                if (! empty($facts['name']) && $customer->name && $customer->name !== $facts['name']) {
                    $aliases = array_values(array_unique(array_merge($attrs['aliases'] ?? [], [$facts['name']])));
                    $attrs['aliases'] = $aliases; // same phone, different name: keep both, flag nothing automatically
                }
                $customer->forceFill(array_filter([
                    'name' => $customer->name ?: ($facts['name'] ?? null),
                    'email' => $customer->email ?: $email,
                    'primary_phone_canonical' => $customer->primary_phone_canonical ?: $phone,
                    'last_seen_at' => max($customer->last_seen_at, isset($facts['placed_at']) ? CarbonImmutable::parse($facts['placed_at']) : now()),
                    'attributes' => $attrs,
                ], fn ($v) => $v !== null))->save();
            }

            if ($phone) {
                $this->link($customer, 'phone', $phone, null, 1.0);
            }
            if ($email) {
                $this->link($customer, 'email', $email, null, $phone ? 1.0 : 0.9);
            }
            if (isset($facts['shop_user_id'])) {
                $this->link($customer, 'shop_user', (string) $facts['shop_user_id'], $store->id, 1.0);
            }
            if (isset($facts['order_ref'])) {
                $this->link($customer, 'shop_order', (string) $facts['order_ref'], $store->id, 1.0);
            }

            if ($created && ! $phone) {
                $this->queueCandidates($customer, $facts['name'] ?? null, $facts['district'] ?? null);
            }
            if ($created) {
                $this->auditor->recordSystem('customer.created', $customer, after: ['business_id' => $businessId, 'has_phone' => $phone !== null, 'store' => $store->slug]);
            }

            return $customer;
        });
    }

    private function byIdentity(string $channel, string $externalId, ?int $storeId): ?Customer
    {
        $identity = CustomerIdentity::query()->where('channel', $channel)->where('external_id', $externalId)
            ->when($storeId !== null, fn ($q) => $q->where('source_store_id', $storeId), fn ($q) => $q->whereNull('source_store_id'))
            ->first();

        return $identity?->customer?->canonical();
    }

    private function link(Customer $customer, string $channel, string $externalId, ?int $storeId, float $confidence): void
    {
        CustomerIdentity::query()->updateOrCreate(
            ['channel' => $channel, 'external_id' => $externalId, 'source_store_id' => $storeId],
            ['customer_id' => $customer->id, 'confidence' => $confidence, 'last_seen_at' => now()],
        );
    }

    /** Rule 4: same name and district within 30 days -> a candidate for a human to merge. */
    private function queueCandidates(Customer $customer, ?string $name, ?string $district): void
    {
        if (! $name || ! $district) {
            return;
        }
        $matches = Customer::query()->where('business_id', $customer->business_id)->where('id', '!=', $customer->id)
            ->whereNull('merged_into_id')->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->where('last_seen_at', '>=', now()->subDays(30))
            ->whereHas('orders', fn ($q) => $q->where('district', $district))
            ->limit(5)->get();
        foreach ($matches as $m) {
            CustomerMergeCandidate::firstOrCreate(['customer_id' => $customer->id, 'candidate_id' => $m->id], ['confidence' => 0.60, 'reason' => 'name+district within 30 days']);
        }
    }

    /** Human-decided merge: identities and facts move to the kept customer; reversible via customer_merges. */
    public function merge(Customer $kept, Customer $absorbed, ?int $byUserId, ?string $reason = null): void
    {
        DB::transaction(function () use ($kept, $absorbed, $byUserId, $reason) {
            $moved = CustomerIdentity::where('customer_id', $absorbed->id)->pluck('id')->all();
            CustomerIdentity::where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id]);
            DB::table('facts_orders')->where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id]);
            $absorbed->forceFill(['merged_into_id' => $kept->id])->save();
            $kept->forceFill([
                'orders_count' => DB::table('facts_orders')->where('customer_id', $kept->id)->count(),
                'lifetime_value' => DB::table('facts_orders')->where('customer_id', $kept->id)->where('status', '!=', 'cancelled')->sum('gross'),
                'primary_phone_canonical' => $kept->primary_phone_canonical ?: $absorbed->primary_phone_canonical,
                'email' => $kept->email ?: $absorbed->email,
            ])->save();
            CustomerMerge::create(['kept_id' => $kept->id, 'absorbed_id' => $absorbed->id, 'reason' => $reason, 'merged_by' => $byUserId, 'moved_identity_ids' => $moved]);
            CustomerMergeCandidate::where(fn ($q) => $q->where('customer_id', $absorbed->id)->orWhere('candidate_id', $absorbed->id))->update(['status' => 'merged', 'decided_by' => $byUserId, 'decided_at' => now()]);
            $this->auditor->record('customer.merged', $kept, after: ['absorbed_id' => $absorbed->id, 'moved_identities' => count($moved)], reason: $reason, actorUserId: $byUserId);
        });
    }
}
