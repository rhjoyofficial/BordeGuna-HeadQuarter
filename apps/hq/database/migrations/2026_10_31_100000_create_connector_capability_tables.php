<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The HQ-completion connectors (docs/144-*): courier parcels, voice transcripts, chat attachments, media
 | drafts, mail ingestion, ERP draft requests and ad drafts. Every table that holds business data carries its
 | `business_id`, and every reader narrows by it (a person limited to business A never sees business B).
 | Nothing here is an external effect: these are HQ's own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_parcels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('consignment_ref', 96);
            $table->string('merchant_order_ref', 96)->nullable();
            $table->foreignId('facts_order_id')->nullable()->constrained('facts_orders')->nullOnDelete();
            $table->string('status', 24)->default('unknown');
            $table->string('raw_status', 120)->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->unsignedInteger('poll_failures')->default(0);
            $table->boolean('terminal')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'provider', 'consignment_ref']);
            $table->index(['business_id', 'terminal']);
            $table->index('facts_order_id');
        });

        Schema::create('courier_parcel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_parcel_id')->constrained('courier_parcels')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('status', 24);
            $table->string('raw_status', 120);
            $table->text('message')->nullable();
            $table->string('source', 12); // poll | webhook
            $table->string('dedupe_key', 64);
            $table->timestamps();

            $table->unique(['courier_parcel_id', 'dedupe_key']);
            $table->index(['courier_parcel_id', 'occurred_at']);
        });

        Schema::create('voice_transcripts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('provider', 48);
            $table->string('language', 16)->nullable();
            $table->text('text');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('ask_message_id')->nullable()->constrained('ask_messages')->nullOnDelete();
            $table->string('original_name', 191);
            $table->string('kind', 16);
            $table->string('status', 16)->default('stored'); // stored | attached | purged
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('media_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('kind', 16)->default('reel');
            $table->text('brief');
            $table->jsonb('source_asset_ids')->nullable();
            $table->string('provider', 48)->nullable();
            $table->string('provider_job_ref', 191)->nullable();
            $table->string('status', 16)->default('queued'); // queued | generating | ready | failed | approved | rejected
            $table->foreignId('result_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('connector', 24);
            $table->string('external_id', 191);
            $table->string('from_address', 191)->nullable();
            $table->string('subject', 255)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('body_text')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'connector', 'external_id']);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('filename', 191);
            $table->string('mime', 96);
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64)->nullable();
            $table->string('scan_status', 16)->default('not_scanned'); // not_scanned | clean | infected
            $table->string('rejected_reason', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('ingested_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('mail_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->foreignId('mail_attachment_id')->nullable()->constrained('mail_attachments')->nullOnDelete();
            $table->string('kind', 24)->default('supplier_bill');
            $table->string('status', 16)->default('draft'); // draft | reviewed | rejected | sent_to_erp | erp_failed
            $table->text('source_text');
            $table->jsonb('extracted');
            $table->string('extractor', 48);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('erp_draft_ref', 191)->nullable();
            $table->text('erp_draft_error')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('ads_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('ad_account_ref', 64);
            $table->string('name', 191);
            $table->jsonb('spec');
            $table->jsonb('checks');
            $table->string('status', 24)->default('created_paused'); // created_paused | failed
            $table->jsonb('platform_refs')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['ads_drafts', 'ingested_documents', 'mail_attachments', 'mail_messages', 'media_drafts', 'chat_attachments', 'voice_transcripts', 'courier_parcel_events', 'courier_parcels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
