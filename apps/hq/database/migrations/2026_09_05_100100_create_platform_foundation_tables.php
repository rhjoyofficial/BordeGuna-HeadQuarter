<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature flags and the append-only audit log.
 *
 * The audit log is protected at the database level on PostgreSQL: a trigger
 * refuses UPDATE and DELETE, so no application code path (and no ordinary user)
 * can rewrite history. On other drivers (tests on SQLite would not use this)
 * the model-level guard in App\Models\AuditLog still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96);
            $table->string('description')->nullable();
            $table->string('environment', 32)->default('*'); // * | production | staging | local
            $table->boolean('enabled')->default(false);
            $table->jsonb('rollout')->nullable(); // e.g. {"businesses":[1,2],"percentage":10}
            $table->timestamps();
            $table->unique(['key', 'environment']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('actor_type', 16); // human|agent|system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action', 96);
            $table->string('target_type')->nullable();
            $table->string('target_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('context')->nullable();
            $table->index(['target_type', 'target_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
            $table->index('occurred_at');
            $table->index('correlation_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hq_audit_logs_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only (% refused)', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_no_update
                    BEFORE UPDATE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION hq_audit_logs_immutable();

                CREATE TRIGGER audit_logs_no_delete
                    BEFORE DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION hq_audit_logs_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs; DROP FUNCTION IF EXISTS hq_audit_logs_immutable();');
        }
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('feature_flags');
    }
};
