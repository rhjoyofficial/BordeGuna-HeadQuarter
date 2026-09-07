<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the shop last said this order looked like this.
 *
 * `synced_at` records when HQ wrote the row, which answers a different question and cannot order
 * two events against each other: a delayed `order.updated` that arrives after a newer one would
 * still have the later `synced_at` and would overwrite good data with stale data. Webhook delivery
 * order is not business event order, and retries make that worse rather than better.
 *
 * So a full-state event now carries its own `occurred_at`, and this column remembers the newest one
 * applied. Older full-state events are then refused instead of silently winning.
 *
 * Null on every existing row, which reads as "no full-state event has been applied yet" - the first
 * one to arrive is therefore always accepted, whatever its timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facts_orders', function (Blueprint $table) {
            $table->timestampTz('last_event_at')->nullable()->after('last_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('facts_orders', function (Blueprint $table) {
            $table->dropColumn('last_event_at');
        });
    }
};
