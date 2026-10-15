<?php

namespace App\Identity;

use App\Gateway\Descriptor\Channel;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerMerge;
use App\Models\CustomerMergeCandidate;
use App\Models\Store;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Unified customer identity (docs/14, docs/35 section H). Deterministic rules only:
 *   1. canonical phone -> confidence 1.0, auto-link
 *   2. lower-cased email -> 0.9, auto-link when no phone conflict
 *   3. an already-linked channel id -> 1.0
 *   4. name + district within 30 days -> 0.6, queued as a merge CANDIDATE for a human, never merged
 * Phone is never unique on customers (one household, one number). Idempotent: resolving the same order twice
 * returns the same customer and adds no duplicate identities.
 *
 * ⛔ Business scope (docs/122, ruling docs/120 A2 Option A). Every lookup and every link names ONE business:
 * the store's for an order, the platform account's for a message. There is no group-wide fallback: the same
 * phone in LaLaDia and in Bionic is two customers with two identity rows.
 * ⛔ No silent re-point. An identity already held by another customer is never moved by a lookup: the link is
 * refused and an open `customer_identity_conflicts` row (plus an audit row the first time) is the resolution
 * requirement. Only a person's merge, or the evidence-backed repair, changes an identity's owner.
 * ⛔ A quarantined identity is never guessed: when it is the only thing that could say who this is, resolution
 * is refused (IdentityQuarantined) and the input stays replayable.
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
        $businessId = (int) $store->business_id;
        $orderRef = isset($facts['order_ref']) && $facts['order_ref'] !== '' ? (string) $facts['order_ref'] : null;
        $shopUser = isset($facts['shop_user_id']) && $facts['shop_user_id'] !== '' ? (string) $facts['shop_user_id'] : null;

        return DB::transaction(function () use ($store, $facts, $phone, $email, $businessId, $orderRef, $shopUser) {
            $customer = null;

            if ($orderRef !== null) {
                $customer = $this->byIdentity($businessId, 'shop_order', $orderRef, (int) $store->id);
            }
            if (! $customer && $shopUser !== null) {
                $customer = $this->byIdentity($businessId, 'shop_user', $shopUser, (int) $store->id);
            }
            if (! $customer && $phone) {
                $customer = $this->byIdentity($businessId, 'phone', $phone, null)
                    ?? Customer::where('business_id', $businessId)->where('primary_phone_canonical', $phone)->whereNull('merged_into_id')->orderBy('id')->first();
            }
            if (! $customer && $email) {
                $customer = $this->byIdentity($businessId, 'email', $email, null)
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
                $customer = $this->absorbFacts($customer->canonical(), $facts, $phone, $email);
            }
            $this->assertInBusiness($customer, $businessId);

            $keys = array_values(array_filter([
                $phone ? ['phone', $phone, null, 1.0] : null,
                $email ? ['email', $email, null, $phone ? 1.0 : 0.9] : null,
                $shopUser !== null ? ['shop_user', $shopUser, (int) $store->id, 1.0] : null,
                $orderRef !== null ? ['shop_order', $orderRef, (int) $store->id, 1.0] : null,
            ]));
            $linked = 0;
            foreach ($keys as [$channel, $externalId, $storeId, $confidence]) {
                $holder = $this->link($customer, $businessId, $channel, $externalId, $storeId, $confidence);
                if ($holder === null) {
                    $linked++;

                    continue;
                }
                if ($created && $linked === 0 && $holder instanceof Customer) {
                    // A concurrent first contact created this key between our lookup and our insert. The customer we
                    // just made holds nothing yet: give it up and take theirs, so one person stays one customer.
                    $customer->delete();
                    $customer = $this->absorbFacts($holder, $facts, $phone, $email);
                    $created = false;
                    $this->link($customer, $businessId, $channel, $externalId, $storeId, $confidence);
                    $linked++;

                    continue;
                }
                $this->refuse($businessId, $channel, $externalId, $storeId, $customer, 'resolver.resolve', ['store_id' => (int) $store->id, 'order_ref' => $orderRef]);
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

    /**
     * The person behind a channel identity, or a new stranger holding only that identity (docs/72 §B).
     *
     * A Messenger PSID or a WhatsApp number is a rule-3 identity: already linked IN THIS BUSINESS means
     * confidence 1.0. Unknown here means a customer of this business exists from this moment with nothing but
     * the channel id, a name only if the channel supplied one, and no phone; a later order or an OTP links them
     * (docs/14). Never merged automatically with anyone, and never matched to another business's customer.
     *
     * @param  array{name?: ?string}  $profile
     */
    public function resolveChannelIdentity(Channel $channel, string $externalId, Business $business, array $profile = []): Customer
    {
        $identityChannel = $channel->identityChannel();
        if ($identityChannel === null || trim($externalId) === '') {
            throw new InvalidArgumentException("[{$channel->value}] carries no customer identity HQ can resolve a person from.");
        }
        $businessId = (int) $business->id;

        return DB::transaction(function () use ($identityChannel, $externalId, $businessId, $profile, $channel) {
            $existing = $this->byIdentity($businessId, $identityChannel, $externalId, null);

            if ($existing !== null) {
                $existing->forceFill(['last_seen_at' => now()])->save();
                CustomerIdentity::query()->key($businessId, $identityChannel, $externalId, null)->update(['last_seen_at' => now()]);

                return $existing;
            }

            $customer = Customer::create([
                'business_id' => $businessId,
                'name' => $profile['name'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'attributes' => ['arrived_via' => $channel->value, 'stranger' => true],
            ]);
            $holder = $this->link($customer, $businessId, $identityChannel, $externalId, null, 1.0);
            if ($holder instanceof Customer) {
                // Lost a first-contact race in this business: the stranger we made holds nothing; theirs stands.
                $customer->delete();
                $holder->forceFill(['last_seen_at' => now()])->save();

                return $holder;
            }
            if ($holder !== null) {
                throw IdentityQuarantined::key($businessId, $identityChannel);
            }
            $this->auditor->recordSystem('customer.created', $customer, after: [
                'business_id' => $businessId, 'has_phone' => false, 'channel' => $channel->value, 'stranger' => true,
            ]);

            return $customer;
        });
    }

    /**
     * The customer a live identity of THIS business names, following merges inside the business only. When no
     * live row exists but a quarantined one does, the value cannot say who this is without a guess: refused.
     */
    private function byIdentity(int $businessId, string $channel, string $externalId, ?int $storeId): ?Customer
    {
        $identity = CustomerIdentity::query()->key($businessId, $channel, $externalId, $storeId)->first();
        if ($identity === null) {
            if ($this->quarantinedRow($businessId, $channel, $externalId, $storeId) !== null) {
                throw IdentityQuarantined::key($businessId, $channel);
            }

            return null;
        }

        $customer = $identity->customer?->canonical();
        if ($customer !== null) {
            $this->assertInBusiness($customer, $businessId);
        }

        return $customer;
    }

    private function quarantinedRow(int $businessId, string $channel, string $externalId, ?int $storeId): ?CustomerIdentity
    {
        return CustomerIdentity::query()
            ->where('business_id', $businessId)->where('channel', $channel)->where('external_id', $externalId)
            ->when($storeId !== null, fn ($q) => $q->where('source_store_id', $storeId), fn ($q) => $q->whereNull('source_store_id'))
            ->whereNotNull('quarantined_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Holds `$customer` to the key in `$businessId`. Returns null when the key is (now) this customer's; the
     * customer already holding it when that is someone else (nothing is moved); or the quarantined row when the
     * key is held in quarantine (nothing is linked).
     *
     * Concurrency: the insert is ON CONFLICT DO NOTHING against the business key (NULLS NOT DISTINCT), so two
     * first contacts for one value in one business cannot both create a row; the loser reads the winner's.
     */
    private function link(Customer $customer, int $businessId, string $channel, string $externalId, ?int $storeId, float $confidence): Customer|CustomerIdentity|null
    {
        $this->assertInBusiness($customer, $businessId);
        if ($storeId !== null && (int) Store::query()->whereKey($storeId)->value('business_id') !== $businessId) {
            throw CrossBusinessIdentity::store($storeId, $businessId);
        }

        $quarantined = $this->quarantinedRow($businessId, $channel, $externalId, $storeId);
        if ($quarantined !== null && ! CustomerIdentity::query()->key($businessId, $channel, $externalId, $storeId)->exists()) {
            return $quarantined;
        }

        $now = now();
        DB::table('customer_identities')->insertOrIgnore([
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => $externalId,
            'source_store_id' => $storeId,
            'confidence' => $confidence,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = CustomerIdentity::query()->key($businessId, $channel, $externalId, $storeId)->lockForUpdate()->firstOrFail();
        if ((int) $row->customer_id !== (int) $customer->id) {
            $holder = $row->customer()->firstOrFail()->canonical();
            if ((int) $holder->id !== (int) $customer->id) {
                return $holder;
            }
        }
        $row->forceFill(['confidence' => $confidence, 'last_seen_at' => $now])->save();

        return null;
    }

    /**
     * The refused re-point, recorded as an open resolution requirement: one row per (identity, claimant) while
     * open, counting repeats, and one audit row the first time it is seen.
     *
     * @param  array<string, mixed>  $context
     */
    private function refuse(int $businessId, string $channel, string $externalId, ?int $storeId, Customer $claimant, string $source, array $context): void
    {
        $identity = CustomerIdentity::query()->key($businessId, $channel, $externalId, $storeId)->first()
            ?? $this->quarantinedRow($businessId, $channel, $externalId, $storeId);
        if ($identity === null) {
            return;
        }
        $reason = $identity->quarantined_at === null ? 'held_by_another_customer' : 'key_quarantined';
        $now = now();
        $row = DB::selectOne(
            'INSERT INTO customer_identity_conflicts
                (business_id, identity_id, kind, reason, holder_customer_id, claimant_customer_id, source, context, occurrences, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, 1, ?, ?, ?, ?)
             ON CONFLICT (identity_id, kind, claimant_customer_id) WHERE resolved_at IS NULL
             DO UPDATE SET occurrences = customer_identity_conflicts.occurrences + 1, last_seen_at = EXCLUDED.last_seen_at, updated_at = EXCLUDED.updated_at
             RETURNING id, (xmax = 0) AS inserted',
            [$businessId, $identity->id, CustomerIdentityConflict::KIND_LINK_REFUSED, $reason, $identity->customer_id, $claimant->id, $source,
                json_encode(array_filter($context + ['channel' => $channel], fn ($v) => $v !== null)), $now, $now, $now, $now],
        );
        if ($row !== null && $row->inserted) {
            $this->auditor->recordSystem('identity.link_refused', $identity, after: [
                'business_id' => $businessId, 'channel' => $channel, 'reason' => $reason, 'conflict_id' => (int) $row->id,
                'holder_customer_id' => (int) $identity->customer_id, 'claimant_customer_id' => (int) $claimant->id,
            ], reason: 'an identity held by another customer is never moved by a lookup');
        }
    }

    private function assertInBusiness(Customer $customer, int $businessId): void
    {
        if ((int) $customer->business_id !== $businessId) {
            throw CrossBusinessIdentity::customer((int) $customer->business_id, $businessId);
        }
    }

    /**
     * Fills what the customer lacks from facts of its own business; a second name is kept as an alias.
     *
     * @param  array<string, mixed>  $facts
     */
    private function absorbFacts(Customer $customer, array $facts, ?string $phone, ?string $email): Customer
    {
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

        return $customer;
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
            ->whereHas('orders', fn ($q) => $q->where('district', $district)->where('business_id', $customer->business_id))
            ->limit(5)->get();
        foreach ($matches as $m) {
            CustomerMergeCandidate::firstOrCreate(['customer_id' => $customer->id, 'candidate_id' => $m->id], ['confidence' => 0.60, 'reason' => 'name+district within 30 days']);
        }
    }

    /** Human-decided merge: identities and facts move to the kept customer; reversible via customer_merges. */
    public function merge(Customer $kept, Customer $absorbed, ?int $byUserId, ?string $reason = null): void
    {
        // ⛔ The chokepoint for ruling docs/103 §11 (docs/109 §6): no caller, human or not, merges across businesses.
        if ((int) $kept->business_id !== (int) $absorbed->business_id) {
            throw CrossBusinessMerge::between((int) $kept->business_id, (int) $absorbed->business_id);
        }

        DB::transaction(function () use ($kept, $absorbed, $byUserId, $reason) {
            // One business on both sides (above), so every identity moves inside it (the composite key holds it).
            $moved = CustomerIdentity::where('customer_id', $absorbed->id)->where('business_id', $kept->business_id)->pluck('id')->all();
            CustomerIdentity::whereIn('id', $moved === [] ? [0] : $moved)->update(['customer_id' => $kept->id]);
            // Only this business's order copies follow the person; a copy of another business hanging under the
            // absorbed row (pre-fix data, docs/117) stays where it is for the evidence-backed repair.
            DB::table('facts_orders')->where('customer_id', $absorbed->id)->where('business_id', $kept->business_id)->update(['customer_id' => $kept->id]);
            // Memory is keyed to the unified customer (docs/09), so what a colleague remembered about
            // the absorbed row follows the person; a live structured value that now collides with the
            // kept customer's value for the same key is closed, the kept one wins (docs/78 B.1).
            $memories = DB::table('memories')->where('customer_id', $absorbed->id)->count();
            foreach (DB::table('memories')->where('customer_id', $absorbed->id)->where('kind', 'structured')->whereNull('valid_to')->get(['id', 'key', 'business_id', 'agent_key']) as $row) {
                $collides = DB::table('memories')->where('customer_id', $kept->id)->where('kind', 'structured')->whereNull('valid_to')
                    ->where('key', $row->key)->where('business_id', $row->business_id)->where('agent_key', $row->agent_key)->exists();
                if ($collides) {
                    DB::table('memories')->where('id', $row->id)->update(['valid_to' => now()]);
                }
            }
            DB::table('memories')->where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id]);
            $absorbed->forceFill(['merged_into_id' => $kept->id])->save();
            $kept->forceFill([
                'orders_count' => DB::table('facts_orders')->where('customer_id', $kept->id)->where('business_id', $kept->business_id)->count(),
                'lifetime_value' => DB::table('facts_orders')->where('customer_id', $kept->id)->where('business_id', $kept->business_id)->where('status', '!=', 'cancelled')->sum('gross'),
                'primary_phone_canonical' => $kept->primary_phone_canonical ?: $absorbed->primary_phone_canonical,
                'email' => $kept->email ?: $absorbed->email,
            ])->save();
            CustomerMerge::create(['kept_id' => $kept->id, 'absorbed_id' => $absorbed->id, 'reason' => $reason, 'merged_by' => $byUserId, 'moved_identity_ids' => $moved]);
            CustomerMergeCandidate::where(fn ($q) => $q->where('customer_id', $absorbed->id)->orWhere('candidate_id', $absorbed->id))->update(['status' => 'merged', 'decided_by' => $byUserId, 'decided_at' => now()]);
            $this->auditor->record('customer.merged', $kept, after: ['absorbed_id' => $absorbed->id, 'moved_identities' => count($moved), 'moved_memories' => $memories], reason: $reason, actorUserId: $byUserId);
        });
    }
}
