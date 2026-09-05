<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Mandatory TOTP two-factor columns on users, login bookkeeping, and the pgvector extension.
 *
 * ⚠ The extension is created ONLY after checking pg_available_extensions. A failed statement
 * inside a PostgreSQL migration transaction poisons the transaction; the COMMIT then becomes a
 * silent ROLLBACK while the migration is still recorded as run, so the users columns would
 * vanish with "DONE" printed. Never let a statement that can fail run inside up() on pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 64)->nullable();
            $table->boolean('is_active')->default(true);
        });

        if (DB::getDriverName() === 'pgsql') {
            $available = DB::selectOne("select 1 as ok from pg_available_extensions where name = 'vector'");
            if ($available) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            } else {
                Log::warning('pgvector is not installed on this PostgreSQL server; HQ Phase 0 stores no vectors, continuing.');
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'last_login_at', 'last_login_ip', 'is_active']);
        });
    }
};
