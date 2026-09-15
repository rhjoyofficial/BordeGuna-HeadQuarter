<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-044: the runtime's own reading of the trusted message for a prohibited capability (a SQL
 * statement, a direct table read, a shell, the governed layer skipped, a secret printed), recorded
 * on every run beside the security-intent signal. Holds the detector version, the kinds matched,
 * the clauses they came from and any detector error (read as a detection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('prohibited_capability')->nullable()->after('authority_source');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('prohibited_capability');
        });
    }
};
