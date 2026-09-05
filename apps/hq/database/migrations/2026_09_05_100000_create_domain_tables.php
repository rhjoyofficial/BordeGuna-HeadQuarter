<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain model (ADR-010): Business, Brand, Store, Product owner, Seller, Channel,
 * Platform account, plus the credential table the stores and accounts reference.
 *
 * HQ mirrors ERP businesses/brands by id and code; it is not the master for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('erp_business_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->jsonb('persona')->nullable();
            $table->jsonb('guidelines')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('account_ref')->nullable();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('credential_type', 32); // api_key|oauth2|token|basic|custom
            $table->text('payload')->nullable(); // encrypted JSON, see model cast
            $table->jsonb('scopes')->nullable();
            $table->string('status', 24)->default('pending'); // pending|active|expired|revoked|invalid
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['provider', 'account_ref']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('adapter', 48); // laladia|bionic|woocommerce|econic_platform|erp_pos
            $table->string('base_url')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->foreignId('credential_id')->nullable()->constrained('integration_credentials')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_product_owners', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['store_id', 'business_id']);
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 24)->default('business'); // business|third_party
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->string('platform', 48); // facebook_page|instagram|whatsapp|meta_pixel|meta_ad_account|gtm|ga4|youtube|linkedin|tiktok|x
            $table->string('account_ref');
            $table->string('name')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->string('approval_status', 24)->default('unknown'); // unknown|not_requested|pending|approved|rejected
            $table->jsonb('quotas')->nullable();
            $table->jsonb('restrictions')->nullable();
            $table->foreignId('credential_id')->nullable()->constrained('integration_credentials')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['platform', 'account_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('store_product_owners');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('integration_credentials');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('businesses');
    }
};
