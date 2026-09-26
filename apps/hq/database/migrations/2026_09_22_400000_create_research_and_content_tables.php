<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 foundation (docs/85, ADR-051 accepted 22 September, docs/88 A.5): the research engine's
 * bounded tasks and cited artifacts, and the content factory's briefs, drafts and publications.
 *
 * ⛔ **Nothing here does anything by existing.** No tool that writes these tables is registered in
 * `config('hq.tools')`, no provider is in `PlatformRegistry`, no flag row exists (`research.engine`,
 * `publishing.write` are off), and no agent holds a tool. The tables are where a gate will start.
 *
 * Three rules are enforced by the database, not by the code that happens to write today:
 *
 * 1. **A research artifact is append-only** (ADR-051 point 2). A refreshed question is a new
 *    version, never an edit, so a claim a draft cited can never change under it.
 * 2. **A brief is append-only and versioned.** A draft records the version it used, so a later
 *    change to the claims policy cannot silently change what an old approval meant.
 * 3. **A draft's words never change after they are checked.** Its text, assets, facts and digest
 *    are frozen by a trigger; an edit is a NEW draft (`parent_draft_id`) that is checked again. Only
 *    its review status may move.
 *
 * Fetched page bodies are kept only while a task is open (`research_sources.body`), because the
 * spans are verified against them; closing the task purges them and keeps the spans and hashes
 * (docs/85 C.18, Brave's storage-rights term).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('contract_version', 32);
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->text('question');
            $table->jsonb('scope')->nullable();

            // The owner-approved source list for this task: hosts or URL prefixes (docs/85 C.2).
            $table->jsonb('approved_sources');
            $table->foreignId('sources_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('freshness_days')->nullable();
            $table->unsignedSmallInteger('max_searches');
            $table->unsignedSmallInteger('max_fetches');
            $table->decimal('cost_ceiling_usd', 10, 4)->default(0);
            $table->unsignedSmallInteger('searches_used')->default(0);
            $table->unsignedSmallInteger('fetches_used')->default(0);
            $table->decimal('cost_spent_usd', 10, 4)->default(0);
            $table->string('search_provider', 24)->nullable();

            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('open'); // open|completed|not_enough_sources|refused
            $table->text('verdict_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        // A search result is a candidate, not a source, until the task fetches and reads it. Only the
        // URL and rank are kept (no snippet): enough to bound fetching to the task's own results.
        Schema::create('research_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('research_tasks')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->text('query');
            $table->unsignedSmallInteger('rank');
            $table->text('url');
            $table->string('url_key', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['task_id', 'url_key']);
        });

        Schema::create('research_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained('research_tasks')->cascadeOnDelete();
            $table->text('url');
            $table->string('url_key', 64);
            $table->string('canonical_host');
            $table->string('title')->nullable();
            $table->string('publisher')->nullable();
            $table->timestampTz('retrieved_at');
            $table->timestampTz('published_at')->nullable();
            $table->string('content_hash', 64);
            $table->unsignedInteger('bytes');
            $table->string('found_via', 32); // approved_list|candidate
            $table->foreignId('candidate_id')->nullable()->constrained('research_candidates')->nullOnDelete();
            $table->string('provenance', 24)->default('third_party');
            $table->text('body')->nullable();
            $table->timestampTz('body_purged_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'url_key']);
        });

        Schema::create('research_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained('research_tasks')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('contract_version', 32);
            $table->text('question');
            $table->jsonb('scope')->nullable();
            $table->jsonb('sources_used');
            $table->jsonb('sources_discarded');
            $table->jsonb('claims');
            $table->jsonb('budget_spent');
            $table->timestampTz('as_of');
            $table->string('digest', 64);
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'version']);
        });

        Schema::create('content_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('persona')->nullable();
            // Written by the owner, never inferred (docs/85 C.15): product line => forbidden and allowed claims.
            $table->jsonb('claims_policy');
            $table->jsonb('banned_words')->nullable();
            $table->text('imagery_rule')->nullable();
            $table->jsonb('typography')->nullable();
            $table->string('model_tier', 16)->nullable();
            $table->string('provenance', 24)->default('business');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'version']);
        });

        Schema::create('content_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->foreignId('research_artifact_id')->nullable()->constrained('research_artifacts')->restrictOnDelete();
            $table->foreignId('parent_draft_id')->nullable()->constrained('content_drafts')->restrictOnDelete();
            $table->string('platform', 32);           // facebook_page|instagram
            $table->string('variant', 32)->default('default');
            $table->text('text');
            $table->string('text_provenance', 24);     // generated|business (a person typed it)
            $table->jsonb('assets');                   // [{media_asset_id, checksum_sha256, role}]
            $table->jsonb('product_facts');            // read from the ERP or the shop at draft time
            $table->jsonb('claim_refs');               // research claim ids the text relies on
            $table->jsonb('checks');                   // content.numbers.v1, claims policy, banned words
            $table->string('status', 24);              // checked|failed_checks|approved|rejected|superseded
            $table->string('content_digest', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
        });

        Schema::create('content_publications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('draft_id')->constrained('content_drafts')->restrictOnDelete();
            $table->foreignId('platform_account_id')->constrained('platform_accounts')->restrictOnDelete();
            $table->string('variant', 32);
            // ADR-011 / docs/85 C.20: one publication per (draft, account, variant), ever.
            $table->string('logical_key')->unique();
            $table->string('content_digest', 64);
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->string('status', 32); // pending|uncertain|published|failed|mismatch|absent|incident
            $table->string('platform_post_id')->nullable();
            $table->jsonb('read_back')->nullable();
            $table->timestampTz('read_back_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hq_research_artifacts_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'research_artifacts is append-only (% refused). File a new version; never edit a claim a draft may cite.', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER research_artifacts_no_update BEFORE UPDATE ON research_artifacts
                    FOR EACH ROW EXECUTE FUNCTION hq_research_artifacts_immutable();
                CREATE TRIGGER research_artifacts_no_delete BEFORE DELETE ON research_artifacts
                    FOR EACH ROW EXECUTE FUNCTION hq_research_artifacts_immutable();

                CREATE OR REPLACE FUNCTION hq_content_briefs_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'content_briefs is append-only (% refused). Write a new version of the brief.', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER content_briefs_no_update BEFORE UPDATE ON content_briefs
                    FOR EACH ROW EXECUTE FUNCTION hq_content_briefs_immutable();
                CREATE TRIGGER content_briefs_no_delete BEFORE DELETE ON content_briefs
                    FOR EACH ROW EXECUTE FUNCTION hq_content_briefs_immutable();

                CREATE OR REPLACE FUNCTION hq_content_drafts_words_frozen() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'content_drafts rows are kept (DELETE refused).';
                    END IF;
                    IF NEW.text IS DISTINCT FROM OLD.text
                       OR NEW.assets IS DISTINCT FROM OLD.assets
                       OR NEW.product_facts IS DISTINCT FROM OLD.product_facts
                       OR NEW.claim_refs IS DISTINCT FROM OLD.claim_refs
                       OR NEW.checks IS DISTINCT FROM OLD.checks
                       OR NEW.content_digest IS DISTINCT FROM OLD.content_digest
                       OR NEW.brief_id IS DISTINCT FROM OLD.brief_id
                       OR NEW.platform IS DISTINCT FROM OLD.platform
                       OR NEW.variant IS DISTINCT FROM OLD.variant THEN
                        RAISE EXCEPTION 'A checked draft''s words are frozen. An edit is a new draft that is checked again.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER content_drafts_words_frozen BEFORE UPDATE OR DELETE ON content_drafts
                    FOR EACH ROW EXECUTE FUNCTION hq_content_drafts_words_frozen();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS content_drafts_words_frozen ON content_drafts;'
                .' DROP FUNCTION IF EXISTS hq_content_drafts_words_frozen();'
                .' DROP TRIGGER IF EXISTS content_briefs_no_update ON content_briefs;'
                .' DROP TRIGGER IF EXISTS content_briefs_no_delete ON content_briefs;'
                .' DROP FUNCTION IF EXISTS hq_content_briefs_immutable();'
                .' DROP TRIGGER IF EXISTS research_artifacts_no_update ON research_artifacts;'
                .' DROP TRIGGER IF EXISTS research_artifacts_no_delete ON research_artifacts;'
                .' DROP FUNCTION IF EXISTS hq_research_artifacts_immutable();'
            );
        }

        Schema::dropIfExists('content_publications');
        Schema::dropIfExists('content_drafts');
        Schema::dropIfExists('content_briefs');
        Schema::dropIfExists('research_artifacts');
        Schema::dropIfExists('research_sources');
        Schema::dropIfExists('research_candidates');
        Schema::dropIfExists('research_tasks');
    }
};
