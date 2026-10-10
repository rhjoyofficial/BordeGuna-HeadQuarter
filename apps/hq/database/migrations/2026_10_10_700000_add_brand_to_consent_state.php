<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/108 §6 (S7): the consent projection learns brand.
 *
 * `consent_events.brand_id` was always recorded, and the resolution rule now enforces it (a LaLaDia
 * opt-in is not a Bionic opt-in). The projection was unique on (customer, business, channel,
 * purpose), so two brands' rows at the same channel and purpose overwrote each other. Purely
 * additive: one nullable column and a wider unique index. `consent_events` is untouched.
 *
 * Down deletes only the brand-scoped projection rows (derived, rebuildable with
 * `ConsentLedger::rebuild()`), so the narrower index can be restored without a collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_state', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('business_id')->constrained('brands')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Backfill from the deciding event. Production holds no consent events at this release;
            // anywhere that does, `ConsentLedger::rebuild()` replays the ledger exactly.
            DB::statement('UPDATE consent_state s SET brand_id = e.brand_id FROM consent_events e WHERE e.id = s.last_event_id');
            DB::statement('DROP INDEX IF EXISTS consent_state_identity_unique');
            DB::statement(
                "CREATE UNIQUE INDEX consent_state_identity_unique ON consent_state
                 (customer_id, business_id, coalesce(brand_id, 0), coalesce(channel, ''), coalesce(purpose, ''))"
            );
        }
    }

    public function down(): void
    {
        DB::table('consent_state')->whereNotNull('brand_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS consent_state_identity_unique');
        }

        Schema::table('consent_state', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX consent_state_identity_unique ON consent_state
                 (customer_id, business_id, coalesce(channel, ''), coalesce(purpose, ''))"
            );
        }
    }
};
