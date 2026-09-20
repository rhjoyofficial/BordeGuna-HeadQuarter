<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-047: the Effective Execution Context a run was resolved under, recorded on the run: who
 * asked, who enabled, the acting identity, the business and brand scope, the tools and permission
 * names both people hold, why anything was narrowed, the resolver version, and a digest of the
 * authority-bearing parts. Additive and nullable: every run before this column has none, and none
 * is written back onto it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('execution_context')->nullable()->after('task_scope');
            $table->string('execution_context_digest', 64)->nullable()->after('execution_context');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['execution_context', 'execution_context_digest']);
        });
    }
};
