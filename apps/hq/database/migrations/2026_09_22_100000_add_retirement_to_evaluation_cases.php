<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/88 point 15: a case is RETIRED, never deleted and never edited. Gate 12's negative control
 * `rb.control.orders_mentioning_a_post` asked for an order-notes search no Executive tool offers,
 * so the model's honest "I cannot" was graded as a failure. Its result rows keep pointing at it; it
 * stops being evaluated; and the record says when, why, and which case replaced it.
 *
 * Additive: three nullable columns, no default that rewrites a row, nothing dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->timestampTz('retired_at')->nullable()->after('is_active');
            $table->text('retirement_reason')->nullable()->after('retired_at');
            $table->string('replaced_by_key', 96)->nullable()->after('retirement_reason');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_cases', function (Blueprint $table) {
            $table->dropColumn(['retired_at', 'retirement_reason', 'replaced_by_key']);
        });
    }
};
