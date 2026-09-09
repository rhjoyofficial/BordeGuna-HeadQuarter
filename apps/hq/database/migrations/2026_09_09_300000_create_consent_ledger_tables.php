<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The consent ledger and its projection (ADR-021, Phase 2.5 B).
 *
 * `customers.consent` is a mutable jsonb blob. It answers "may we contact this person now", and
 * compliance asks a different question months later about a specific message somebody is
 * complaining about: was consent valid AT THE MOMENT WE SENT THAT. The value that was true at send
 * time has since been overwritten, so the column cannot answer, and no amount of care at send time
 * creates the record afterwards.
 *
 * So: an append-only ledger, immutable at the database level for the same reason `audit_logs` is,
 * plus a projection that exists only for speed and can be thrown away and rebuilt from the ledger
 * at any past instant.
 *
 * ⚠ The old column is NOT dropped. It becomes a migration source and then a legacy field, so this
 * migration is purely additive and a rollback loses nothing that was not derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            // ⚠ RESTRICT, not cascade, and no nullOnDelete anywhere on this table. Both of those
            // referential actions are writes: a cascade DELETEs rows and a null-on-delete UPDATEs
            // them, and the immutability triggers below refuse both. A foreign key that can only
            // ever fire into a refusal is a deletion that fails at 2am for a reason nobody expects,
            // so the table simply does not offer one.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();

            // null channel = every channel for this business. That is how do-not-contact is
            // expressed at the person level rather than being repeated once per channel and
            // forgotten on the channel added next year.
            $table->string('channel', 24)->nullable();
            // Nullable for the same reason as channel: "do not contact me at all" is one event, not
            // one per purpose plus one more for whichever purpose is invented next year.
            $table->string('purpose', 32)->nullable(); // service|marketing|research|recording|synthetic_identity
            $table->string('state', 24);     // granted|withdrawn|expired|do_not_contact
            $table->string('basis', 24);     // explicit|checkout|inbound|contract|imported|operator

            // WHEN it happened, separately from when we heard about it. An import of last year's
            // paper forms records an occurred_at of last year and a recorded_at of today, and a
            // reconstruction "as at" a past instant needs both to be honest.
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();

            // Evidence: who said so, where it is written down, and what it said.
            $table->string('evidence_ref')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('actor_type', 16)->default('system'); // human|system|agent|customer
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->text('reason')->nullable();

            // Retention travels with the consent that justified collecting the data.
            $table->string('data_class', 24)->default('personal');
            $table->unsignedInteger('retention_days')->nullable();

            $table->uuid('correlation_id')->nullable();
            // Deliberately NOT a foreign key, for the reason above: every referential action
            // available here is a write this table refuses. The id is recorded so a trace can join
            // by hand, which is all an audit needs.
            $table->unsignedBigInteger('tool_call_id')->nullable();

            $table->index(['customer_id', 'business_id', 'occurred_at']);
            $table->index(['business_id', 'channel', 'purpose']);
            $table->index('recorded_at');
            $table->index('correlation_id');
        });

        // The projection. Every column here is derivable; nothing is authoritative.
        Schema::create('consent_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('channel', 24)->nullable();
            $table->string('purpose', 32)->nullable();
            $table->string('state', 24);
            $table->timestampTz('effective_from');
            $table->timestampTz('expires_at')->nullable();
            // The projection may cascade: it is derived, and losing it costs a rebuild, not evidence.
            $table->foreignId('last_event_id')->constrained('consent_events')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'business_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // A null channel must collide with another null channel: person-level do-not-contact is
            // one row, not one per null. PostgreSQL treats NULLs as distinct in a unique index, so
            // the coalesce is what makes the projection actually unique.
            DB::statement(
                "CREATE UNIQUE INDEX consent_state_identity_unique ON consent_state
                 (customer_id, business_id, coalesce(channel, ''), coalesce(purpose, ''))"
            );

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hq_consent_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'consent_events is append-only (% refused). Record a new event; never edit history.', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER consent_events_no_update
                    BEFORE UPDATE ON consent_events
                    FOR EACH ROW EXECUTE FUNCTION hq_consent_events_immutable();

                CREATE TRIGGER consent_events_no_delete
                    BEFORE DELETE ON consent_events
                    FOR EACH ROW EXECUTE FUNCTION hq_consent_events_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS consent_events_no_update ON consent_events;'
                .' DROP TRIGGER IF EXISTS consent_events_no_delete ON consent_events;'
                .' DROP FUNCTION IF EXISTS hq_consent_events_immutable();'
            );
            DB::statement('DROP INDEX IF EXISTS consent_state_identity_unique');
        }

        Schema::dropIfExists('consent_state');
        Schema::dropIfExists('consent_events');
    }
};
