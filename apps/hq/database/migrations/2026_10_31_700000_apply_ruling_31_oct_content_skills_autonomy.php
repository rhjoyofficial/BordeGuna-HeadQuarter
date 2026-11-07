<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ChatGPT ruling of 31 October 2026, items 13 to 17 (docs/145, builder R3). Applied on top of the M4 tables.
 *
 *   content_testimonials       ruling 13: a real customer's statement with recorded consent for the exact use,
 *                              evidence of the original, provenance, brand/channel/period permission. Rows are
 *                              never deleted; what a consent covers never widens; a revocation is final.
 *   content_drafts (+cols)     ruling 13: the testimonial a draft quotes (frozen like the words);
 *                              ruling 15: self_decided, its reason and when, for a sole reviewer's own approval.
 *   avatar_consents/generations ruling 14: the database refuses any customer-facing avatar use.
 *   autonomy_grants (+cols)    ruling 17: a one-way lapse (when, which condition, why). A lapsed grant, and a
 *                              ladder grant that is no longer active, never becomes active again.
 */
return new class extends Migration
{
    /** Kept in step with `App\Avatar\AvatarConsents::CUSTOMER_FACING_USES`. */
    private const CUSTOMER_FACING = ['customer_reply', 'customer_support', 'support_reply', 'inbox_reply', 'conversation_reply'];

    public function up(): void
    {
        Schema::create('content_testimonials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('customer_label', 120);        // the name the customer agreed to be shown under; no phone, no address
            $table->text('original_statement');            // the customer's own words, verbatim
            $table->unsignedBigInteger('original_evidence_asset_id');   // screenshot, recording or note of the original statement
            $table->unsignedBigInteger('consent_document_asset_id');    // the recorded consent covering this exact use
            $table->timestampTz('consent_given_at');
            $table->jsonb('permitted_modalities');         // text|image|video|voice
            $table->jsonb('brand_ids');
            $table->jsonb('channels');                     // facebook_page|instagram
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('source_channel', 32);          // whatsapp|messenger|instagram|email|phone|in_person|review_site
            $table->string('source_ref', 200)->nullable(); // where the original can be found again (no customer phone)
            $table->timestampTz('captured_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->foreign('original_evidence_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $table->foreign('consent_document_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $table->index(['business_id', 'revoked_at']);
        });

        Schema::table('content_drafts', function (Blueprint $table) {
            $table->foreignId('testimonial_id')->nullable()->constrained('content_testimonials')->restrictOnDelete();
            $table->boolean('self_decided')->default(false);
            $table->text('self_decided_reason')->nullable();
            $table->timestampTz('self_decided_at')->nullable();
        });

        Schema::table('autonomy_grants', function (Blueprint $table) {
            $table->timestampTz('lapsed_at')->nullable();
            $table->string('lapse_code', 40)->nullable();  // evidence_below_threshold|ai_paused|pause_unreadable|threshold_retired|safety_condition_invalid
            $table->text('lapse_reason')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $customerFacing = "ARRAY['".implode("','", self::CUSTOMER_FACING)."']";

        DB::unprepared(<<<SQL
            CREATE TRIGGER content_testimonials_no_delete BEFORE DELETE ON content_testimonials FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();

            CREATE OR REPLACE FUNCTION hq_r3_testimonial_guard() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'A revoked testimonial consent is final. Record a new one.';
                END IF;
                IF NEW.original_statement IS DISTINCT FROM OLD.original_statement OR NEW.original_evidence_asset_id IS DISTINCT FROM OLD.original_evidence_asset_id
                   OR NEW.consent_document_asset_id IS DISTINCT FROM OLD.consent_document_asset_id OR NEW.permitted_modalities IS DISTINCT FROM OLD.permitted_modalities
                   OR NEW.brand_ids IS DISTINCT FROM OLD.brand_ids OR NEW.channels IS DISTINCT FROM OLD.channels
                   OR NEW.valid_from IS DISTINCT FROM OLD.valid_from OR NEW.valid_until IS DISTINCT FROM OLD.valid_until
                   OR NEW.business_id IS DISTINCT FROM OLD.business_id OR NEW.customer_label IS DISTINCT FROM OLD.customer_label THEN
                    RAISE EXCEPTION 'What a testimonial consent covers never changes. Revoke it and record a new one.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER content_testimonials_guard BEFORE UPDATE ON content_testimonials FOR EACH ROW EXECUTE FUNCTION hq_r3_testimonial_guard();

            -- The testimonial a draft quotes is part of what was checked: it never changes after the draft is written.
            CREATE OR REPLACE FUNCTION hq_r3_draft_testimonial_frozen() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.testimonial_id IS DISTINCT FROM OLD.testimonial_id THEN
                    RAISE EXCEPTION 'The testimonial a draft quotes never changes. Write a new draft.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER content_drafts_testimonial_frozen BEFORE UPDATE ON content_drafts FOR EACH ROW EXECUTE FUNCTION hq_r3_draft_testimonial_frozen();

            -- Ruling 14: an avatar never answers a customer or acts as customer support.
            ALTER TABLE avatar_consents ADD CONSTRAINT avatar_consents_never_customer_facing
                CHECK (NOT (permitted_uses ?| {$customerFacing}));
            ALTER TABLE avatar_generations ADD CONSTRAINT avatar_generations_never_customer_facing
                CHECK (status = 'refused' OR NOT (use = ANY ({$customerFacing})));

            -- Ruling 17: a lapse is one-way. A lapsed grant stays lapsed; a ladder grant that left `active` never returns.
            CREATE OR REPLACE FUNCTION hq_r3_grant_lapse_one_way() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.status = 'lapsed' AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'A lapsed autonomy grant never comes back. A person makes a new grant after the conditions are verified again.';
                END IF;
                IF OLD.ladder_threshold_id IS NOT NULL AND OLD.status <> 'active' AND NEW.status = 'active' THEN
                    RAISE EXCEPTION 'A ladder grant that is no longer active is never reactivated. A person makes a new grant.';
                END IF;
                IF OLD.lapsed_at IS NOT NULL AND (NEW.lapsed_at IS DISTINCT FROM OLD.lapsed_at OR NEW.lapse_code IS DISTINCT FROM OLD.lapse_code) THEN
                    RAISE EXCEPTION 'The record of a lapse is never rewritten.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER autonomy_grants_lapse_one_way BEFORE UPDATE ON autonomy_grants FOR EACH ROW EXECUTE FUNCTION hq_r3_grant_lapse_one_way();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS autonomy_grants_lapse_one_way ON autonomy_grants; DROP FUNCTION IF EXISTS hq_r3_grant_lapse_one_way();'
                .' ALTER TABLE avatar_generations DROP CONSTRAINT IF EXISTS avatar_generations_never_customer_facing;'
                .' ALTER TABLE avatar_consents DROP CONSTRAINT IF EXISTS avatar_consents_never_customer_facing;'
                .' DROP TRIGGER IF EXISTS content_drafts_testimonial_frozen ON content_drafts; DROP FUNCTION IF EXISTS hq_r3_draft_testimonial_frozen();'
                .' DROP TRIGGER IF EXISTS content_testimonials_guard ON content_testimonials; DROP FUNCTION IF EXISTS hq_r3_testimonial_guard();'
                .' DROP TRIGGER IF EXISTS content_testimonials_no_delete ON content_testimonials;'
            );
        }

        Schema::table('autonomy_grants', fn (Blueprint $t) => $t->dropColumn(['lapsed_at', 'lapse_code', 'lapse_reason']));
        Schema::table('content_drafts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('testimonial_id');
            $t->dropColumn(['self_decided', 'self_decided_reason', 'self_decided_at']);
        });
        Schema::dropIfExists('content_testimonials');
    }
};
