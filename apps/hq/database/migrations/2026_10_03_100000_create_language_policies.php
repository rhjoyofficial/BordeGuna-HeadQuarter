<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The customer language policy (docs/92 A.8, stream W1).
 *
 * Which language and script a Support reply must be written in, per language tag the conversation core
 * records on a thread, stated ONCE as versioned, owner-controlled data, so that the business rule
 * (today: a mixed Bangla-and-English message is answered in Bengali script) can change by an explicit,
 * audited activation and never by a prompt or a model's preference.
 *
 * ⭐ **Version 1 is seeded ACTIVE here.** It is the rule that already stood (the Support prompt and the
 * P3-G2 case `sim.mixed.script.agent_active` both required it) written down as data, not a new decision:
 * `bn` -> Bangla in Bengali script, `en` -> English in Latin script, `banglish` (the core's tag for Bangla
 * and English in one message) -> Bangla in Bengali script, romanised Bangla on its own -> Bangla in Latin
 * script, no detected script -> no requirement. Every later version is proposed as data and activated only
 * by `hq:language-policy:activate --by --reason --apply`. The rules below are the same, literally, as
 * `App\Conversation\Language\LanguagePolicies::V1`, and the suite holds the two digests equal.
 *
 * ⛔ **A version is immutable once activated.** The active row accepts only its own retirement by a named
 * person with a reason; a retired row accepts nothing; a proposed row becomes active only with a person, a
 * time, a reason and the digest. Same trigger pattern as `autonomy_evidence_policies`; the events table is
 * append-only. At most one version is active (partial unique index).
 *
 * Additive on `agent_runs`: `language_requirement` (jsonb) records, on a thread run, the policy version
 * and the requirement the runtime resolved, every script validation (counts only, never the text), the
 * repairs attempted, successful and rejected, and the final verdict. Null on runs that are not thread runs
 * and on runs made before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('version')->unique();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('status', 16)->default('proposed'); // proposed|active|retired
            $table->jsonb('rules');
            $table->foreignId('supersedes_id')->nullable()->constrained('language_policies')->restrictOnDelete();

            $table->foreignId('proposed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('proposed_at')->useCurrent();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->text('activation_reason')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();

            // sha256 of the canonical rules, written at activation: what a run was held to.
            $table->char('content_digest', 64)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('language_policy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('language_policies')->restrictOnDelete();
            $table->string('event', 16); // proposed|activated|retired
            // Deliberately not a foreign key: every referential action would be a write this table refuses.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('snapshot');
            $table->char('content_digest', 64);
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['policy_id', 'occurred_at']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('language_requirement')->nullable()->after('support_workflow');
        });

        $this->seedVersionOne();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX language_policies_one_active ON language_policies ((true)) WHERE status = 'active'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hq_language_policy_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'proposed' OR OLD.activated_at IS NOT NULL THEN
                        RAISE EXCEPTION 'language_policies: version % was activated and is kept forever (DELETE refused)', OLD.version;
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'retired' THEN
                    RAISE EXCEPTION 'language_policies: version % is retired and immutable (UPDATE refused)', OLD.version;
                END IF;

                IF OLD.status = 'active' THEN
                    IF NEW.status <> 'retired'
                        OR NEW.retired_by IS NULL OR NEW.retired_at IS NULL OR NEW.retirement_reason IS NULL
                        OR NEW.version IS DISTINCT FROM OLD.version OR NEW.uuid IS DISTINCT FROM OLD.uuid
                        OR NEW.name IS DISTINCT FROM OLD.name OR NEW.summary IS DISTINCT FROM OLD.summary
                        OR NEW.rules::text IS DISTINCT FROM OLD.rules::text
                        OR NEW.supersedes_id IS DISTINCT FROM OLD.supersedes_id
                        OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by OR NEW.proposed_at IS DISTINCT FROM OLD.proposed_at
                        OR NEW.activated_by IS DISTINCT FROM OLD.activated_by OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                        OR NEW.activation_reason IS DISTINCT FROM OLD.activation_reason
                        OR NEW.content_digest IS DISTINCT FROM OLD.content_digest THEN
                        RAISE EXCEPTION 'language_policies: version % is active and immutable; it can only be retired, by a named person with a reason', OLD.version;
                    END IF;
                    RETURN NEW;
                END IF;

                -- OLD.status = 'proposed'
                IF NEW.status = 'retired' THEN
                    RAISE EXCEPTION 'language_policies: version % was never active, so there is nothing to retire', OLD.version;
                END IF;

                IF NEW.status = 'active' THEN
                    IF NEW.activated_by IS NULL OR NEW.activated_at IS NULL OR NEW.activation_reason IS NULL OR NEW.content_digest IS NULL THEN
                        RAISE EXCEPTION 'language_policies: activation needs a named person, a time, a reason and the content digest';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER language_policies_guard
                BEFORE UPDATE OR DELETE ON language_policies
                FOR EACH ROW EXECUTE FUNCTION hq_language_policy_guard();

            CREATE OR REPLACE FUNCTION hq_language_policy_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'language_policy_events is append-only (% refused)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER language_policy_events_no_update
                BEFORE UPDATE ON language_policy_events
                FOR EACH ROW EXECUTE FUNCTION hq_language_policy_events_immutable();

            CREATE TRIGGER language_policy_events_no_delete
                BEFORE DELETE ON language_policy_events
                FOR EACH ROW EXECUTE FUNCTION hq_language_policy_events_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS language_policy_events_no_update ON language_policy_events;'
                .' DROP TRIGGER IF EXISTS language_policy_events_no_delete ON language_policy_events;'
                .' DROP TRIGGER IF EXISTS language_policies_guard ON language_policies;'
                .' DROP FUNCTION IF EXISTS hq_language_policy_events_immutable();'
                .' DROP FUNCTION IF EXISTS hq_language_policy_guard();'
            );
            DB::statement('DROP INDEX IF EXISTS language_policies_one_active');
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('language_requirement');
        });

        Schema::dropIfExists('language_policy_events');
        Schema::dropIfExists('language_policies');
    }

    /**
     * Version 1, the standing rule, active from the start. Written before the triggers exist, as a plain
     * insert, so that no named person is required for the rule that was already in force; every later
     * version goes through the command. Idempotent: a table that already holds a version is left alone.
     */
    private function seedVersionOne(): void
    {
        if (DB::table('language_policies')->exists()) {
            return;
        }

        // ⚠ Literally LanguagePolicies::V1['rules'], keys sorted at every level (the canonical form the digest is taken over).
        $rules = [
            'allowed_tokens' => ['order_id', 'sku', 'url', 'email', 'phone', 'number', 'brand_name', 'permitted_term', 'identifier'],
            'brand_names' => ['LaLaDia', 'Bionic Garden', 'Bor de Guna', 'Bôr dé Gûna', 'লালাদিয়া', 'বায়োনিক গার্ডেন', 'বর দে গুনা'],
            'max_other_script_share' => 0.2,
            'permitted_terms' => ['Hilsa Pickle', 'Hilsa', 'Pink Salt', 'Mustard Oil', 'Ghee', 'Messenger', 'WhatsApp', 'Facebook', 'Instagram'],
            'rules' => [
                'banglish' => ['language' => 'bn', 'script' => 'latin'],
                'bn' => ['language' => 'bn', 'script' => 'bengali'],
                'en' => ['language' => 'en', 'script' => 'latin'],
                'mixed' => ['language' => 'bn', 'script' => 'bengali'],
            ],
            'thread_tags' => ['banglish' => 'mixed', 'bn' => 'bn', 'en' => 'en'],
        ];
        $json = (string) json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $json);
        $now = now();
        $reason = 'docs/92 A.1: the standing business rule (a mixed Bangla-and-English message is answered in Bengali script), '
            .'written down as version 1 of the language policy and active from the migration that created it. Changing it is an owner decision made by activating a new version.';

        $id = DB::table('language_policies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Customer reply script, v1',
            'summary' => 'The rule that stood before docs/92: Bangla in Bengali script, English in Latin script, a mixed Bangla-and-English message '
                .'answered in Bengali script, romanised Bangla answered as written. Owner-controlled; a new version changes it.',
            'status' => 'active',
            'rules' => $json,
            'proposed_at' => $now,
            'activated_at' => $now,
            'activation_reason' => $reason,
            'content_digest' => $digest,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('language_policy_events')->insert([
            'policy_id' => $id,
            'event' => 'activated',
            'actor_user_id' => null,
            'actor_label' => 'migration 2026_10_03_100000 (docs/92 A.1, the standing rule)',
            'reason' => $reason,
            'snapshot' => (string) json_encode(['version' => 1, 'name' => 'Customer reply script, v1', 'rules' => $rules], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_digest' => $digest,
            'occurred_at' => $now,
        ]);
    }
};
