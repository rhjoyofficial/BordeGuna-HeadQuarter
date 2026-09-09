<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an account's capabilities were last checked against the platform (ADR-022, Phase 2.5 A).
 *
 * `capabilities`, `scopes`, `approval_status`, `quotas` and `restrictions` have sat on
 * `platform_accounts` since Phase 0 with nothing reading or writing any of them. `CapabilityResolver`
 * now reads them, which raises the question the columns never had to answer before: how old is this?
 *
 * A capability list nobody has re-checked since App Review changed is worse than no list, because it
 * looks current. The date is here so a stale one can be seen rather than trusted.
 *
 * Additive and reversible: two nullable columns, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->timestampTz('capabilities_verified_at')->nullable()->after('restrictions');
            $table->string('capabilities_verified_by', 96)->nullable()->after('capabilities_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_accounts', function (Blueprint $table) {
            $table->dropColumn(['capabilities_verified_at', 'capabilities_verified_by']);
        });
    }
};
