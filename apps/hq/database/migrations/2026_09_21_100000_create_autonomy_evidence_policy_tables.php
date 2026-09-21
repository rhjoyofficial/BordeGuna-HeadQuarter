<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The autonomy evidence policy (ADR-048, docs/81 C.4).
 *
 * Until now the only evidence requirement in the system was a number on an individual grant. This
 * is the requirement stated ONCE, per autonomy level and evaluation dimension, versioned and
 * governed, independent of any grant.
 *
 * ⛔ **Every numeric field is nullable, and NULL means "not decided".** No number ships with this
 * migration and none is defaulted. A policy holding a NULL can be proposed and shown; the database
 * itself refuses to let it become `active`.
 *
 * ⛔ **A version is immutable once activated.** Its requirement rows can no longer be inserted,
 * changed or removed, the policy row accepts only its own retirement, and a retired row accepts
 * nothing. Same trigger pattern as `audit_logs` and `consent_events`, and restrict-only foreign
 * keys for the same reason: a cascade is a write these triggers refuse.
 *
 * Purely additive. `agent_runs.autonomy_evidence` is null on every run made with no active policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomy_evidence_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('version')->unique();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('status', 16)->default('proposed'); // proposed|active|retired
            $table->foreignId('supersedes_id')->nullable()->constrained('autonomy_evidence_policies')->restrictOnDelete();

            $table->foreignId('proposed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('proposed_at')->useCurrent();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->text('activation_reason')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();

            // sha256 of the canonical requirement set, written at activation: what a run was judged on.
            $table->char('content_digest', 64)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('autonomy_evidence_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('autonomy_evidence_policies')->restrictOnDelete();
            $table->string('autonomy_level', 4); // a1..a4. A0 needs no evidence; A5 is unreachable.
            $table->string('dimension', 24);     // one of the six ADR-030 dimensions, never "overall"

            // NULL = not decided. There is no default, on purpose.
            $table->decimal('min_agreement_rate', 4, 3)->nullable();
            $table->unsignedInteger('min_sample_count')->nullable();
            $table->unsignedInteger('max_evidence_age_days')->nullable();

            // The only safe reading, and the only value: an unmeasured case blocks.
            $table->string('required_measured_state', 24)->default('fully_measured');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'autonomy_level', 'dimension'], 'autonomy_evidence_requirements_identity');
        });

        Schema::create('autonomy_evidence_hard_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('autonomy_evidence_policies')->restrictOnDelete();
            $table->string('autonomy_level', 4);
            $table->string('check_key', 48); // a key of DeterministicGrader::CHECKS, e.g. must_not_call

            // No rate column: a hard check is 100 percent or it has failed.
            $table->unsignedInteger('min_sample_count')->nullable();
            $table->unsignedInteger('max_evidence_age_days')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'autonomy_level', 'check_key'], 'autonomy_evidence_hard_checks_identity');
        });

        Schema::create('autonomy_evidence_policy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('autonomy_evidence_policies')->restrictOnDelete();
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
            $table->jsonb('autonomy_evidence')->nullable()->after('autonomy_constraints');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX autonomy_evidence_policies_one_active ON autonomy_evidence_policies ((true)) WHERE status = 'active'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hq_autonomy_evidence_policy_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'proposed' OR OLD.activated_at IS NOT NULL THEN
                        RAISE EXCEPTION 'autonomy_evidence_policies: version % was activated and is kept forever (DELETE refused)', OLD.version;
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'retired' THEN
                    RAISE EXCEPTION 'autonomy_evidence_policies: version % is retired and immutable (UPDATE refused)', OLD.version;
                END IF;

                IF OLD.status = 'active' THEN
                    IF NEW.status <> 'retired'
                        OR NEW.retired_by IS NULL OR NEW.retired_at IS NULL OR NEW.retirement_reason IS NULL
                        OR NEW.version IS DISTINCT FROM OLD.version OR NEW.uuid IS DISTINCT FROM OLD.uuid
                        OR NEW.name IS DISTINCT FROM OLD.name OR NEW.summary IS DISTINCT FROM OLD.summary
                        OR NEW.supersedes_id IS DISTINCT FROM OLD.supersedes_id
                        OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by OR NEW.proposed_at IS DISTINCT FROM OLD.proposed_at
                        OR NEW.activated_by IS DISTINCT FROM OLD.activated_by OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                        OR NEW.activation_reason IS DISTINCT FROM OLD.activation_reason
                        OR NEW.content_digest IS DISTINCT FROM OLD.content_digest THEN
                        RAISE EXCEPTION 'autonomy_evidence_policies: version % is active and immutable; it can only be retired, by a named person with a reason', OLD.version;
                    END IF;
                    RETURN NEW;
                END IF;

                -- OLD.status = 'proposed'
                IF NEW.status = 'retired' THEN
                    RAISE EXCEPTION 'autonomy_evidence_policies: version % was never active, so there is nothing to retire', OLD.version;
                END IF;

                IF NEW.status = 'active' THEN
                    IF NEW.activated_by IS NULL OR NEW.activated_at IS NULL OR NEW.activation_reason IS NULL OR NEW.content_digest IS NULL THEN
                        RAISE EXCEPTION 'autonomy_evidence_policies: activation needs a named person, a time, a reason and the content digest';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM autonomy_evidence_requirements r WHERE r.policy_id = NEW.id) THEN
                        RAISE EXCEPTION 'autonomy_evidence_policies: version % has no requirement and cannot be activated', OLD.version;
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM autonomy_evidence_requirements r WHERE r.policy_id = NEW.id
                          AND (r.min_agreement_rate IS NULL OR r.min_sample_count IS NULL OR r.max_evidence_age_days IS NULL
                               OR r.required_measured_state <> 'fully_measured')
                    ) OR EXISTS (
                        SELECT 1 FROM autonomy_evidence_hard_checks h WHERE h.policy_id = NEW.id
                          AND (h.min_sample_count IS NULL OR h.max_evidence_age_days IS NULL)
                    ) THEN
                        RAISE EXCEPTION 'autonomy_evidence_policies: version % holds an undecided (NULL) field and cannot be activated', OLD.version;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER autonomy_evidence_policies_guard
                BEFORE UPDATE OR DELETE ON autonomy_evidence_policies
                FOR EACH ROW EXECUTE FUNCTION hq_autonomy_evidence_policy_guard();

            CREATE OR REPLACE FUNCTION hq_autonomy_evidence_rows_guard() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                parent_id bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    parent_id := OLD.policy_id;
                ELSE
                    parent_id := NEW.policy_id;
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.policy_id IS DISTINCT FROM OLD.policy_id THEN
                    RAISE EXCEPTION '%: a row never moves between policy versions', TG_TABLE_NAME;
                END IF;

                SELECT status INTO parent_status FROM autonomy_evidence_policies WHERE id = parent_id;

                IF parent_status IS DISTINCT FROM 'proposed' THEN
                    RAISE EXCEPTION '%: the policy version is % and its rows are immutable (% refused). Propose a new version.', TG_TABLE_NAME, coalesce(parent_status, 'missing'), TG_OP;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER autonomy_evidence_requirements_guard
                BEFORE INSERT OR UPDATE OR DELETE ON autonomy_evidence_requirements
                FOR EACH ROW EXECUTE FUNCTION hq_autonomy_evidence_rows_guard();

            CREATE TRIGGER autonomy_evidence_hard_checks_guard
                BEFORE INSERT OR UPDATE OR DELETE ON autonomy_evidence_hard_checks
                FOR EACH ROW EXECUTE FUNCTION hq_autonomy_evidence_rows_guard();

            CREATE OR REPLACE FUNCTION hq_autonomy_evidence_policy_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'autonomy_evidence_policy_events is append-only (% refused)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER autonomy_evidence_policy_events_no_update
                BEFORE UPDATE ON autonomy_evidence_policy_events
                FOR EACH ROW EXECUTE FUNCTION hq_autonomy_evidence_policy_events_immutable();

            CREATE TRIGGER autonomy_evidence_policy_events_no_delete
                BEFORE DELETE ON autonomy_evidence_policy_events
                FOR EACH ROW EXECUTE FUNCTION hq_autonomy_evidence_policy_events_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS autonomy_evidence_policy_events_no_update ON autonomy_evidence_policy_events;'
                .' DROP TRIGGER IF EXISTS autonomy_evidence_policy_events_no_delete ON autonomy_evidence_policy_events;'
                .' DROP TRIGGER IF EXISTS autonomy_evidence_hard_checks_guard ON autonomy_evidence_hard_checks;'
                .' DROP TRIGGER IF EXISTS autonomy_evidence_requirements_guard ON autonomy_evidence_requirements;'
                .' DROP TRIGGER IF EXISTS autonomy_evidence_policies_guard ON autonomy_evidence_policies;'
                .' DROP FUNCTION IF EXISTS hq_autonomy_evidence_policy_events_immutable();'
                .' DROP FUNCTION IF EXISTS hq_autonomy_evidence_rows_guard();'
                .' DROP FUNCTION IF EXISTS hq_autonomy_evidence_policy_guard();'
            );
            DB::statement('DROP INDEX IF EXISTS autonomy_evidence_policies_one_active');
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('autonomy_evidence');
        });

        Schema::dropIfExists('autonomy_evidence_policy_events');
        Schema::dropIfExists('autonomy_evidence_hard_checks');
        Schema::dropIfExists('autonomy_evidence_requirements');
        Schema::dropIfExists('autonomy_evidence_policies');
    }
};
