<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media asset metadata (Phase 2.5 E).
 *
 * ⛔ **Metadata in Postgres, bytes behind a StorageDriver, and no filesystem path anywhere in the
 * domain model.** The row names a driver and an opaque key. Moving from the VPS disk to object
 * storage is then a driver swap, not a migration of every row plus every URL somebody built out of
 * a path years earlier.
 *
 * ⚠ ADR-014 chose local-only backup on the grounds that HQ's data is derived and reconstructable
 * from the ERP and the shops. Generated media is NOT: nothing else in the group holds it. This
 * table is the first thing in HQ that a lost disk actually loses, and the backup posture has to be
 * revisited before Phase 6 generates anything at volume. Recorded here because the row is where
 * somebody will be standing when it matters.
 *
 * Provenance travels with the asset for the same reason it travels with context (ADR-024): a
 * generated image is not evidence of anything, and a customer-supplied one is untrusted input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('kind', 16);            // image|video|audio|document
            $table->string('mime', 96);
            $table->unsignedBigInteger('bytes');
            $table->string('checksum_sha256', 64);

            // Where the bytes are, without saying how to reach them.
            $table->string('storage_driver', 32);
            $table->string('storage_key');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('provenance', 24)->default('business'); // system|business|customer|third_party|generated
            $table->string('source_ref')->nullable();

            /** Generator detail when this was synthesised: {provider, model, prompt_ref, seed}. */
            $table->jsonb('generator')->nullable();

            /** Rights: {license, owner, allowed_uses:[], attribution_required, expires_at}. */
            $table->jsonb('rights')->nullable();

            /**
             * The consent that permits this asset's use, for a real person's likeness or voice
             * (ConsentPurpose::SyntheticIdentity). Not a foreign key, because consent_events is
             * append-only and every referential action available here is a write it refuses.
             */
            $table->unsignedBigInteger('consent_event_id')->nullable();

            $table->string('data_class', 24)->default('internal');
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'kind']);
            $table->index('checksum_sha256');
            $table->index(['storage_driver', 'storage_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
