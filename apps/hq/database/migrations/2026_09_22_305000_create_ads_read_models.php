<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 read models (ADR-007), stream S (`docs/88` A point 4). Additive: two new tables, nothing
 * else touched.
 *
 * ⛔ **Copies of what an ad platform said, never truth.** Every row is `third_party` provenance
 * (ADR-024): Meta's own count, with the time HQ read it and the API version it spoke. Delivered
 * orders and cost prices are the ERP's and the shops' (Business provenance) and are never stored
 * here. Money is a decimal in the ACCOUNT's own currency, never converted (`docs/84` C.21).
 *
 * ⚠ Empty on production and it stays empty: the sync that fills them is defined but not scheduled,
 * no ads credential exists, and the Meta provider is registered nowhere (gate P5-G1 changes that).
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per connected ad account: the owner's spending limit as last READ (HQ never
        // writes it, ADR-050 clarification 3), the account's currency and time zone.
        Schema::create('facts_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->constrained('platform_accounts')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->string('platform', 48);
            $table->string('account_ref', 64);
            $table->string('name')->nullable();
            $table->char('currency', 3);
            $table->string('timezone', 64)->nullable();
            $table->string('status', 32);
            // The platform's basic units, exactly as sent (reconciliation), and the major-unit reading.
            $table->bigInteger('spend_cap_minor')->nullable();      // null = no limit set (NOT a limit of zero)
            $table->bigInteger('amount_spent_minor')->nullable();
            $table->decimal('spend_cap', 16, 2)->nullable();
            $table->decimal('amount_spent', 16, 2)->nullable();
            $table->string('provenance', 16)->default('third_party');
            $table->string('source', 32);                             // meta_ads
            $table->string('api_version', 16);
            $table->timestampTz('fetched_at');
            $table->string('sync_ref', 128)->nullable();              // the correlation id of the call that read it
            $table->timestampsTz();
            $table->unique('platform_account_id');
            $table->index(['business_id', 'brand_id']);
        });

        // One row per object per day, at the level it was read (campaign, adset, ad).
        Schema::create('facts_ad_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->constrained('platform_accounts')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            // Which store the spend promoted, when something says so (an experiment, later). Null is
            // "not known", never "every store".
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('platform', 48);
            $table->string('account_ref', 64);
            $table->string('level', 16);                              // campaign | adset | ad
            $table->string('object_ref', 64);
            $table->string('object_name')->nullable();
            $table->string('campaign_ref', 64)->nullable();
            $table->string('adset_ref', 64)->nullable();
            $table->string('ad_ref', 64)->nullable();
            $table->date('spend_date');                               // the day in the ACCOUNT's time zone
            $table->char('currency', 3);
            $table->decimal('spend', 16, 2);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedInteger('purchases')->nullable();         // Meta's attributed count, not delivered orders
            $table->decimal('purchase_value', 16, 2)->nullable();
            $table->string('provenance', 16)->default('third_party');
            $table->string('source', 32);
            $table->string('api_version', 16);
            $table->timestampTz('fetched_at');
            $table->string('sync_ref', 128)->nullable();
            $table->timestampsTz();
            $table->unique(['platform_account_id', 'level', 'object_ref', 'spend_date'], 'facts_ad_spend_object_day_unique');
            $table->index(['business_id', 'spend_date']);
            $table->index(['brand_id', 'spend_date']);
            $table->index(['campaign_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts_ad_spend');
        Schema::dropIfExists('facts_ad_accounts');
    }
};
