<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruling 1 Nov 2026 (FX): the USD to BDT rate is the owner's DATA, dated and audited, not a constant in config.
 *
 * One row per rate the owner set, append-only (a change is a new row; the database refuses UPDATE and DELETE, like
 * `audit_logs` and `consent_events`). The current rate is the newest row for the pair.
 *
 *  - `reference_rate`: the published rate the owner read (for example a Bangladesh Bank reference). AI cost DISPLAY uses it,
 *    labelled an estimate.
 *  - `reserve_rate`: the conservative rate a paid media budget RESERVATION uses. The owner enters it directly, so the figure
 *    the budget uses is exactly the one he typed; `safety_margin_percent` is recorded beside it (derived, for the record).
 *  - `as_of` and `valid_until`: a rate past `valid_until` is stale. Stale or missing, paid media is refused (fail closed);
 *    the display falls back to USD.
 *
 * The first row is the ruling's own: reference BDT 123.99, reserve BDT 136.50 (margin 10.0895%), valid to 30 Nov 2026.
 * `media_budget_ledger.fx_rate_id` records which rate each reservation used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('reference_rate', 14, 6);
            $table->decimal('reserve_rate', 14, 6);
            $table->decimal('safety_margin_percent', 8, 4);
            $table->string('source', 255);
            $table->timestampTz('as_of');
            $table->timestampTz('valid_until');
            // Null only for the row this migration writes (the ruling's initial rate); every later row names a person.
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at');
            $table->index(['base_currency', 'quote_currency', 'id']);
        });

        Schema::table('media_budget_ledger', function (Blueprint $table) {
            $table->foreignId('fx_rate_id')->nullable()->after('fx_rate_to_bdt')->constrained('fx_rates')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_currencies CHECK (base_currency ~ '^[A-Z]{3}$' AND quote_currency ~ '^[A-Z]{3}$' AND base_currency <> quote_currency)");
            DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_positive CHECK (reference_rate > 0 AND reserve_rate >= reference_rate AND safety_margin_percent >= 0)');
            DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_window CHECK (valid_until > as_of)');
            DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_text CHECK (length(trim(source)) > 0 AND length(trim(reason)) > 0)');

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hq_fx_rates_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'fx_rates is append-only (% refused). Set a new rate; never edit history.', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER fx_rates_no_update
                    BEFORE UPDATE ON fx_rates
                    FOR EACH ROW EXECUTE FUNCTION hq_fx_rates_immutable();

                CREATE TRIGGER fx_rates_no_delete
                    BEFORE DELETE ON fx_rates
                    FOR EACH ROW EXECUTE FUNCTION hq_fx_rates_immutable();
            SQL);
        }

        DB::table('fx_rates')->insert([
            'base_currency' => 'USD',
            'quote_currency' => 'BDT',
            'reference_rate' => '123.990000',
            'reserve_rate' => '136.500000',
            // (136.50 / 123.99 - 1) x 100, to four places.
            'safety_margin_percent' => '10.0895',
            'source' => 'Bangladesh Bank reference 29 Oct 2026',
            'as_of' => '2026-10-29 00:00:00+06',
            'valid_until' => '2026-11-30 23:59:59+06',
            'set_by_user_id' => null,
            'reason' => 'Initial rate from the owner ruling of 1 Nov 2026; replaces the fixed USD 130 plus 5% in config.',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('media_budget_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fx_rate_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS fx_rates_no_update ON fx_rates;'
                .' DROP TRIGGER IF EXISTS fx_rates_no_delete ON fx_rates;'
                .' DROP FUNCTION IF EXISTS hq_fx_rates_immutable();'
            );
        }

        Schema::dropIfExists('fx_rates');
    }
};
