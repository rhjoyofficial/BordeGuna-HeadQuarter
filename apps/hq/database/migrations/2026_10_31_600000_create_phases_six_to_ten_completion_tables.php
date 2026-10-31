<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HQ completion programme, agent M4 (docs/145): the tables that finish Phases 6, 8, 9 and 10.
 *
 * ⛔ **Nothing here does anything by existing.** Every row is written by a named person through a
 * permission-gated screen; no tool that writes these tables is registered, no flag row exists, no agent
 * holds a tool, and the autonomy ladder has no threshold (so nothing is ever grantable) until Joy sets one.
 *
 * Phase 6
 *   content_claims_rules      what may and may not be said per product line, per business (docs/145-a)
 *   content_calendar_entries  a brand's planned posts; a plan, never a post
 *   research_digests          competitor / market notes assembled ONLY from filed, cited research claims
 * Phase 8
 *   avatar_consents           a written consent record for a person's face or voice (docs/145-b)
 *   avatar_generations        every request, refused or not, tied to the consent it rested on
 * Phase 9
 *   skills                    the skill registry (docs/10), status moved only by people (docs/145-c)
 *   agent_delegations         every delegation asked for, with the narrowed authority it resolved to
 * Phase 10
 *   autonomy_ladder_thresholds  Joy's numbers per tool per business (docs/145-d); none ship
 *   autonomy_grants (+cols)     who revoked, when, why; which ladder threshold a grant rested on
 *
 * Rules held by the database, not only by the code that writes today:
 *   - consent rows, rules and thresholds are never deleted (a revocation or retirement is a column);
 *   - a delegation record and a digest are append-only;
 *   - a threshold's numbers never change after it is set: a change is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_claims_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('product_line', 64);          // '*' = every product line of the business
            $table->string('kind', 16);                  // forbidden|allowed
            $table->jsonb('phrases');                    // the words a draft is checked for (forbidden) or may use (allowed)
            $table->text('reason');
            $table->string('legal_ref', 120)->nullable(); // e.g. "CRPA 2009 s.44"
            $table->text('proof_ref')->nullable();       // an allowed claim names the proof it rests on
            $table->string('status', 16)->default('active'); // active|retired
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retire_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'product_line']);
        });

        Schema::create('content_calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->date('planned_for');
            $table->string('occasion', 64)->nullable();  // eid, pohela_boishakh, hilsa_season, ...
            $table->string('platform', 32);              // facebook_page|instagram
            $table->jsonb('product_lines');
            $table->text('idea');
            $table->string('status', 16)->default('planned'); // planned|drafted|approved|published|skipped
            $table->foreignId('draft_id')->nullable()->constrained('content_drafts')->nullOnDelete();
            $table->text('skip_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'planned_for']);
            $table->index(['brand_id', 'status']);
        });

        Schema::create('research_digests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('kind', 16);                  // competitor|market
            $table->string('title', 160);
            $table->jsonb('artifact_ids');
            $table->jsonb('entries');                    // [{claim_id, artifact_id, kind, statement, sources[{url, title, retrieved_at, span}]}]
            $table->text('note')->nullable();            // a person's own words, labelled as such, numbers checked
            $table->jsonb('checks');
            $table->string('digest', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'kind']);
        });

        Schema::create('avatar_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('person_label', 120);          // a name as the person agreed to be named; no phone, no address
            $table->string('kind', 24);                   // real_person|synthetic_persona
            $table->jsonb('modalities');                  // ["face","voice"]
            $table->jsonb('permitted_uses');              // product_promo|brand_presenter|internal
            $table->jsonb('brand_ids');                   // the brands it may appear for; [] = none
            $table->unsignedBigInteger('consent_document_asset_id')->nullable(); // the signed document, in the asset library
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('expires_at');            // consent always ends; there is no open-ended consent
            $table->string('provider', 32)->nullable();
            $table->string('provider_avatar_ref', 120)->nullable();
            $table->string('provider_voice_ref', 120)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->foreign('consent_document_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $table->index(['business_id', 'revoked_at']);
        });

        Schema::create('avatar_generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('consent_id')->nullable()->constrained('avatar_consents')->restrictOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('use', 32);
            $table->string('modality', 16);               // face|voice
            $table->text('script');
            $table->string('status', 16);                 // refused|requested|generated|withdrawn|failed
            $table->text('refusal_reason')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_job_ref', 120)->nullable();
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consent_id', 'status']);
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 64);
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->text('purpose');
            $table->jsonb('required_tools');              // the ONLY tools the skill may call
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete(); // null = group-wide
            $table->string('proposer_type', 16);          // person|agent
            $table->string('proposal_ref', 120)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('proposed'); // proposed|approved|active|suspended|retired
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
        });

        Schema::create('agent_delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_run_id')->nullable();
            $table->string('from_agent_key', 64);
            $table->string('to_agent_key', 64);
            $table->foreignId('skill_id')->nullable()->constrained('skills')->restrictOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);                 // resolved|refused
            $table->text('refusal_reason')->nullable();
            $table->string('parent_context_digest', 64)->nullable();
            $table->jsonb('effective');                   // business_ids, brand_ids, tools, permissions, narrowed
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['to_agent_key', 'created_at']);
        });

        Schema::create('autonomy_ladder_thresholds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('tool_key', 64);
            $table->string('max_level', 4);               // a1..a4 (A5 is unreachable)
            $table->unsignedInteger('min_approved_succeeded');
            $table->unsignedSmallInteger('max_error_permille');     // errors per 1,000 executed
            $table->unsignedSmallInteger('max_rejection_permille'); // human "no" per 1,000 decided
            $table->unsignedSmallInteger('window_days');
            $table->text('reason');
            $table->string('status', 16)->default('active'); // active|retired
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'tool_key', 'status']);
        });

        Schema::table('autonomy_grants', function (Blueprint $table) {
            $table->foreignId('ladder_threshold_id')->nullable()->constrained('autonomy_ladder_thresholds')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX autonomy_ladder_thresholds_one_active ON autonomy_ladder_thresholds (business_id, tool_key) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX skills_one_active_per_key ON skills (key) WHERE status = 'active'");

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hq_m4_no_delete() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% rows are kept (DELETE refused). Retire or revoke instead.', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION hq_m4_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only (% refused).', TG_TABLE_NAME, TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER content_claims_rules_no_delete BEFORE DELETE ON content_claims_rules FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();
                CREATE TRIGGER avatar_consents_no_delete BEFORE DELETE ON avatar_consents FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();
                CREATE TRIGGER avatar_generations_no_delete BEFORE DELETE ON avatar_generations FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();
                CREATE TRIGGER skills_no_delete BEFORE DELETE ON skills FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();
                CREATE TRIGGER autonomy_ladder_thresholds_no_delete BEFORE DELETE ON autonomy_ladder_thresholds FOR EACH ROW EXECUTE FUNCTION hq_m4_no_delete();
                CREATE TRIGGER agent_delegations_append_only BEFORE UPDATE OR DELETE ON agent_delegations FOR EACH ROW EXECUTE FUNCTION hq_m4_append_only();
                CREATE TRIGGER research_digests_append_only BEFORE UPDATE OR DELETE ON research_digests FOR EACH ROW EXECUTE FUNCTION hq_m4_append_only();

                -- A rule's words and a threshold's numbers never change; only its retirement may be written.
                CREATE OR REPLACE FUNCTION hq_m4_claims_rule_frozen() RETURNS trigger AS $$
                BEGIN
                    IF NEW.phrases IS DISTINCT FROM OLD.phrases OR NEW.kind IS DISTINCT FROM OLD.kind
                       OR NEW.product_line IS DISTINCT FROM OLD.product_line OR NEW.business_id IS DISTINCT FROM OLD.business_id
                       OR (OLD.status = 'retired' AND NEW.status IS DISTINCT FROM OLD.status) THEN
                        RAISE EXCEPTION 'A claims rule is never edited or revived; retire it and write a new one.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER content_claims_rules_frozen BEFORE UPDATE ON content_claims_rules FOR EACH ROW EXECUTE FUNCTION hq_m4_claims_rule_frozen();

                CREATE OR REPLACE FUNCTION hq_m4_threshold_frozen() RETURNS trigger AS $$
                BEGIN
                    IF NEW.tool_key IS DISTINCT FROM OLD.tool_key OR NEW.business_id IS DISTINCT FROM OLD.business_id
                       OR NEW.max_level IS DISTINCT FROM OLD.max_level OR NEW.min_approved_succeeded IS DISTINCT FROM OLD.min_approved_succeeded
                       OR NEW.max_error_permille IS DISTINCT FROM OLD.max_error_permille
                       OR NEW.max_rejection_permille IS DISTINCT FROM OLD.max_rejection_permille
                       OR NEW.window_days IS DISTINCT FROM OLD.window_days
                       OR (OLD.status = 'retired' AND NEW.status IS DISTINCT FROM OLD.status) THEN
                        RAISE EXCEPTION 'A ladder threshold is never edited or revived; retire it and set a new one.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER autonomy_ladder_thresholds_frozen BEFORE UPDATE ON autonomy_ladder_thresholds FOR EACH ROW EXECUTE FUNCTION hq_m4_threshold_frozen();

                -- A revoked consent stays revoked: no update may clear the revocation or widen what it covered.
                CREATE OR REPLACE FUNCTION hq_m4_consent_guard() RETURNS trigger AS $$
                BEGIN
                    IF OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A revoked avatar consent is final. Record a new consent.';
                    END IF;
                    IF NEW.modalities IS DISTINCT FROM OLD.modalities OR NEW.permitted_uses IS DISTINCT FROM OLD.permitted_uses
                       OR NEW.brand_ids IS DISTINCT FROM OLD.brand_ids OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                       OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.business_id IS DISTINCT FROM OLD.business_id
                       OR NEW.consent_document_asset_id IS DISTINCT FROM OLD.consent_document_asset_id THEN
                        RAISE EXCEPTION 'What a consent covers never changes. Revoke it and record a new consent.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER avatar_consents_guard BEFORE UPDATE ON avatar_consents FOR EACH ROW EXECUTE FUNCTION hq_m4_consent_guard();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS avatar_consents_guard ON avatar_consents; DROP FUNCTION IF EXISTS hq_m4_consent_guard();'
                .' DROP TRIGGER IF EXISTS autonomy_ladder_thresholds_frozen ON autonomy_ladder_thresholds; DROP FUNCTION IF EXISTS hq_m4_threshold_frozen();'
                .' DROP TRIGGER IF EXISTS content_claims_rules_frozen ON content_claims_rules; DROP FUNCTION IF EXISTS hq_m4_claims_rule_frozen();'
                .' DROP TRIGGER IF EXISTS research_digests_append_only ON research_digests;'
                .' DROP TRIGGER IF EXISTS agent_delegations_append_only ON agent_delegations;'
                .' DROP TRIGGER IF EXISTS autonomy_ladder_thresholds_no_delete ON autonomy_ladder_thresholds;'
                .' DROP TRIGGER IF EXISTS skills_no_delete ON skills;'
                .' DROP TRIGGER IF EXISTS avatar_generations_no_delete ON avatar_generations;'
                .' DROP TRIGGER IF EXISTS avatar_consents_no_delete ON avatar_consents;'
                .' DROP TRIGGER IF EXISTS content_claims_rules_no_delete ON content_claims_rules;'
                .' DROP FUNCTION IF EXISTS hq_m4_append_only(); DROP FUNCTION IF EXISTS hq_m4_no_delete();'
            );
        }

        Schema::table('autonomy_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ladder_threshold_id');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoked_at', 'revoke_reason']);
        });

        foreach (['autonomy_ladder_thresholds', 'agent_delegations', 'skills', 'avatar_generations', 'avatar_consents', 'research_digests', 'content_calendar_entries', 'content_claims_rules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
