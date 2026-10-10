<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The playbook store (docs/112, stream S6; ruling docs/103 §8).
 *
 * Written business guidance per business, brand and kind (pricing authority, escalation, product claims,
 * ...), versioned like `language_policies`: a draft is proposed as data, reviewed by a person, activated
 * by a named enabled person with a reason, and read-only from then on. At most one version is active per
 * (business, brand-or-business-wide, kind). Every version is kept forever; a draft nobody wants is
 * withdrawn, never deleted.
 *
 * ⛔ A playbook GRANTS NOTHING. No table here is read by the resolver, the gateway, the policy engine or
 * the consent ledger (held structurally by PlaybookStructureTest). The only runtime reader is context
 * assembly, which hands the text to the model as business data behind a framing block that says so.
 *
 * ⛔ Immutability at the database (pgsql triggers): a retired or withdrawn row never changes; an active
 * row accepts only its own retirement by a named person with a reason; a row becomes active only from
 * `reviewed`, with a person, a time, a reason and its digest; content changes only while `draft`; DELETE
 * is refused for every status. The events table is append-only.
 *
 * Additive on `agent_runs`: `playbooks` (jsonb) records, per run, every playbook version the run loaded
 * (id, key, version, content digest, size), what the budget skipped, and why nothing was loaded when
 * nothing was. Null on runs made before this existed.
 *
 * ⭐ The store ships EMPTY. Nothing is seeded: the owner writes his own policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbook_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('kind', 48);
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft|in_review|reviewed|active|retired|withdrawn
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('body');
            // sha256 of the canonical content (kind, title, body); rewritten on every draft save, frozen at activation.
            $table->char('content_digest', 64);
            $table->foreignId('supersedes_id')->nullable()->constrained('playbook_versions')->restrictOnDelete();

            $table->foreignId('drafted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('drafted_at')->useCurrent();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->text('activation_reason')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['business_id', 'brand_id', 'kind']);
        });

        Schema::create('playbook_version_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_version_id')->constrained('playbook_versions')->restrictOnDelete();
            $table->string('event', 16); // drafted|edited|submitted|approved|sent_back|activated|retired|withdrawn
            // Deliberately not a foreign key: every referential action would be a write this table refuses.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('snapshot');
            $table->char('content_digest', 64);
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['playbook_version_id', 'occurred_at']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('playbooks')->nullable()->after('language_requirement');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One version number per key, with "business-wide" counted as a value and not as "distinct from itself".
        DB::statement('CREATE UNIQUE INDEX playbook_versions_key_version ON playbook_versions (business_id, COALESCE(brand_id, 0), kind, version)');
        // At most one active version per key.
        DB::statement("CREATE UNIQUE INDEX playbook_versions_one_active ON playbook_versions (business_id, COALESCE(brand_id, 0), kind) WHERE status = 'active'");
        // The size cap, at the database as well as in validation (docs/112 §4).
        DB::statement('ALTER TABLE playbook_versions ADD CONSTRAINT playbook_versions_body_size CHECK (char_length(body) <= 16000)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hq_playbook_version_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'playbook_versions: version % (%) is kept forever (DELETE refused); withdraw it instead', OLD.version, OLD.status;
                END IF;

                IF OLD.status IN ('retired', 'withdrawn') THEN
                    RAISE EXCEPTION 'playbook_versions: version % is % and immutable (UPDATE refused)', OLD.version, OLD.status;
                END IF;

                -- The key and the content never change after the draft stage.
                IF OLD.status <> 'draft' AND (
                    NEW.business_id IS DISTINCT FROM OLD.business_id OR NEW.brand_id IS DISTINCT FROM OLD.brand_id
                    OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.version IS DISTINCT FROM OLD.version OR NEW.uuid IS DISTINCT FROM OLD.uuid
                    OR NEW.title IS DISTINCT FROM OLD.title OR NEW.summary IS DISTINCT FROM OLD.summary OR NEW.body IS DISTINCT FROM OLD.body
                    OR NEW.content_digest IS DISTINCT FROM OLD.content_digest
                    OR NEW.drafted_by IS DISTINCT FROM OLD.drafted_by OR NEW.drafted_at IS DISTINCT FROM OLD.drafted_at
                ) THEN
                    RAISE EXCEPTION 'playbook_versions: version % is % and its content and key are frozen; write a new draft', OLD.version, OLD.status;
                END IF;

                IF OLD.status = 'active' THEN
                    IF NEW.status <> 'retired'
                        OR NEW.retired_by IS NULL OR NEW.retired_at IS NULL OR NEW.retirement_reason IS NULL
                        OR NEW.supersedes_id IS DISTINCT FROM OLD.supersedes_id
                        OR NEW.submitted_by IS DISTINCT FROM OLD.submitted_by OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
                        OR NEW.reviewed_by IS DISTINCT FROM OLD.reviewed_by OR NEW.reviewed_at IS DISTINCT FROM OLD.reviewed_at
                        OR NEW.review_note IS DISTINCT FROM OLD.review_note
                        OR NEW.activated_by IS DISTINCT FROM OLD.activated_by OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                        OR NEW.activation_reason IS DISTINCT FROM OLD.activation_reason THEN
                        RAISE EXCEPTION 'playbook_versions: version % is active and immutable; it can only be retired, by a named person with a reason', OLD.version;
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status = 'active' THEN
                    IF OLD.status <> 'reviewed' THEN
                        RAISE EXCEPTION 'playbook_versions: only a reviewed version can be activated (version % is %)', OLD.version, OLD.status;
                    END IF;
                    IF NEW.activated_by IS NULL OR NEW.activated_at IS NULL OR NEW.activation_reason IS NULL OR NEW.content_digest IS NULL THEN
                        RAISE EXCEPTION 'playbook_versions: activation needs a named person, a time, a reason and the content digest';
                    END IF;
                END IF;

                IF NEW.status = 'retired' THEN
                    RAISE EXCEPTION 'playbook_versions: version % was never active, so there is nothing to retire; withdraw it', OLD.version;
                END IF;

                IF NEW.status = 'withdrawn' AND (NEW.withdrawn_by IS NULL OR NEW.withdrawn_at IS NULL OR NEW.withdrawal_reason IS NULL) THEN
                    RAISE EXCEPTION 'playbook_versions: withdrawal needs a named person, a time and a reason';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER playbook_versions_guard
                BEFORE UPDATE OR DELETE ON playbook_versions
                FOR EACH ROW EXECUTE FUNCTION hq_playbook_version_guard();

            CREATE OR REPLACE FUNCTION hq_playbook_version_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'playbook_version_events is append-only (% refused)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER playbook_version_events_no_update
                BEFORE UPDATE ON playbook_version_events
                FOR EACH ROW EXECUTE FUNCTION hq_playbook_version_events_immutable();

            CREATE TRIGGER playbook_version_events_no_delete
                BEFORE DELETE ON playbook_version_events
                FOR EACH ROW EXECUTE FUNCTION hq_playbook_version_events_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS playbook_version_events_no_update ON playbook_version_events;'
                .' DROP TRIGGER IF EXISTS playbook_version_events_no_delete ON playbook_version_events;'
                .' DROP TRIGGER IF EXISTS playbook_versions_guard ON playbook_versions;'
                .' DROP FUNCTION IF EXISTS hq_playbook_version_events_immutable();'
                .' DROP FUNCTION IF EXISTS hq_playbook_version_guard();'
            );
            DB::statement('DROP INDEX IF EXISTS playbook_versions_one_active');
            DB::statement('DROP INDEX IF EXISTS playbook_versions_key_version');
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('playbooks');
        });

        Schema::dropIfExists('playbook_version_events');
        Schema::dropIfExists('playbook_versions');
    }
};
