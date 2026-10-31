<?php

namespace App\Identity\Conflicts;

use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Whether the rows support bringing a quarantined identity back live (docs/140, ruling docs/131 item 3). READ ONLY: it
 * is what the conflict screen shows next to a held identity and what `ConflictResolver` checks under its locks before a
 * release. A release is supported only when no other live identity carries the same key in the business, its store (if
 * any) is of the same business, and, for a row held as `contradicted_by_evidence`, at least one order, thread or consent
 * record of the identity's OWN business now uses it. Anything else stays held; a person cannot argue it out of quarantine.
 */
final class ReleaseEvidence
{
    /**
     * Why the rows do not support bringing a held identity back live, by code (`live_copy`, `store_business`,
     * `no_own_use`); an empty array when they do.
     *
     * @return array<string, string>
     */
    public function blockers(CustomerIdentity $identity, int $businessId): array
    {
        $blockers = [];
        $storeId = $identity->source_store_id === null ? null : (int) $identity->source_store_id;
        $live = CustomerIdentity::query()->key($businessId, (string) $identity->channel, (string) $identity->external_id, $storeId)
            ->whereKeyNot($identity->id)->value('id');
        if ($live !== null) {
            $blockers['live_copy'] = "another live identity (#{$live}) already carries this key in this business";
        }
        if ($storeId !== null && (int) DB::table('stores')->where('id', $storeId)->value('business_id') !== $businessId) {
            $blockers['store_business'] = "its store (#{$storeId}) belongs to another business";
        }
        if ($identity->quarantine_reason === 'contradicted_by_evidence' && ! $this->usedInBusiness($identity, $businessId)) {
            $blockers['no_own_use'] = 'no order, thread or consent record of this business uses it; every use is in another business';
        }

        return $blockers;
    }

    /** At least one row of THIS business that uses the identity: the evidence the migration found missing. */
    private function usedInBusiness(CustomerIdentity $identity, int $businessId): bool
    {
        $byPhone = $identity->channel === 'phone'
            && DB::table('facts_orders')->where('business_id', $businessId)->where('customer_phone_canonical', $identity->external_id)->exists();

        return $byPhone
            || DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')
                ->where('p.identity_id', $identity->id)->where('v.business_id', $businessId)->exists()
            || DB::table('consent_events')->where('identity_id', $identity->id)->where('business_id', $businessId)->exists();
    }
}
