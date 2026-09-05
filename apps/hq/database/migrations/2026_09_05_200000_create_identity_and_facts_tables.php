<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: unified customer identity (docs/14, docs/35 section H) and the orders read model
 * (facts_orders). Both are rebuildable from the systems of record; HQ is the master only for
 * the identity graph, merges and consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('primary_phone_canonical', 20)->nullable(); // 01XXXXXXXXX; never unique (one household, one number)
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('lifetime_value', 14, 2)->default(0);
            $table->jsonb('consent')->nullable();     // {marketing_sms, marketing_whatsapp, marketing_messenger, source, at}
            $table->jsonb('attributes')->nullable();  // aliases, language, preferred delivery...
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'primary_phone_canonical']);
            $table->index(['business_id', 'email']);
        });

        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('channel', 24); // phone|email|shop_user|shop_order|erp_customer|messenger_psid|instagram_igsid|whatsapp
            $table->string('external_id', 191);
            $table->foreignId('source_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->decimal('confidence', 3, 2)->default(1.00);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_id', 'source_store_id'], 'customer_identities_channel_unique');
            $table->index('customer_id');
        });

        Schema::create('customer_merge_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('confidence', 3, 2);
            $table->string('reason', 64);
            $table->string('status', 16)->default('pending'); // pending|merged|dismissed
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'candidate_id']);
        });

        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kept_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('absorbed_id')->constrained('customers')->cascadeOnDelete();
            $table->string('reason', 191)->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('moved_identity_ids')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::create('facts_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('order_ref', 64);
            $table->string('source', 32)->nullable();   // shop `source`: checkout|landing|admin|hq_ai
            $table->string('status', 24);
            $table->string('payment_status', 24)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_phone_canonical', 20)->nullable();
            $table->string('district', 64)->nullable();
            $table->string('thana', 64)->nullable();
            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('campaign_ref')->nullable();     // fbc/utm hints when the shop sends them
            $table->jsonb('lines')->nullable();              // [{sku, name, qty, unit_price, total, variant_id, combo_id}]
            $table->string('last_event_id', 64)->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'order_ref']);
            $table->index(['business_id', 'placed_at']);
            $table->index(['customer_phone_canonical']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts_orders');
        Schema::dropIfExists('customer_merges');
        Schema::dropIfExists('customer_merge_candidates');
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customers');
    }
};
