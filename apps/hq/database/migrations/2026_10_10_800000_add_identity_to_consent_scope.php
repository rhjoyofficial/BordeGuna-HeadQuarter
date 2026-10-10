<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/114 §2 (S7 follow-up, ruling (i)): consent scope learns the IDENTITY.
 *
 * A customer with two phones, or a phone and a Messenger PSID, may say "stop" on one of them. Until
 * now the ledger could only say it per channel, so a restriction recorded for one WhatsApp number was
 * a restriction for every WhatsApp number of that person, and a grant on another number of the same
 * channel could not be told apart from a grant on the restricted one. Scope becomes business x brand
 * x channel x purpose x identity, each of the last four either specific or "all" (null).
 *
 * Additive. `consent_events.identity_id` is a nullable FK to `customer_identities`, RESTRICT like
 * every foreign key on the append-only table (an UPDATE or DELETE cascaded into it would only ever
 * fire into the immutability trigger). The projection's column may cascade: it is derived and
 * `ConsentLedger::rebuild()` replays the ledger. No existing row changes; a null identity reads as
 * "every identity of the customer", which is exactly what every existing row meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_events', function (Blueprint $table) {
            $table->foreignId('identity_id')->nullable()->after('brand_id')->constrained('customer_identities')->restrictOnDelete();
            $table->index(['identity_id']);
        });

        Schema::table('consent_state', function (Blueprint $table) {
            $table->foreignId('identity_id')->nullable()->after('brand_id')->constrained('customer_identities')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS consent_state_identity_unique');
            DB::statement(
                "CREATE UNIQUE INDEX consent_state_identity_unique ON consent_state
                 (customer_id, business_id, coalesce(brand_id, 0), coalesce(identity_id, 0), coalesce(channel, ''), coalesce(purpose, ''))"
            );
        }
    }

    public function down(): void
    {
        // Derived rows only: the ledger keeps its identity-scoped events (append-only), and a projection
        // rebuilt on the narrower index would simply not carry them.
        DB::table('consent_state')->whereNotNull('identity_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS consent_state_identity_unique');
        }

        Schema::table('consent_state', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_id');
        });

        Schema::table('consent_events', function (Blueprint $table) {
            $table->dropIndex(['identity_id']);
            $table->dropConstrainedForeignId('identity_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX consent_state_identity_unique ON consent_state
                 (customer_id, business_id, coalesce(brand_id, 0), coalesce(channel, ''), coalesce(purpose, ''))"
            );
        }
    }
};
