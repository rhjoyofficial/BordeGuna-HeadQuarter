<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/86: the runtime's reading of the trusted message for an outbound action the agent's ROLE
 * forbids (a message sent, content published, a customer answered), recorded on every run beside the
 * security-intent and prohibited-capability signals and apart from both. Holds the detector and
 * policy versions, the role, the kinds asked for and forbidden, the clauses they came from, whether
 * an independent request for information remained, whether the run was refused whole, and any
 * detector error (read as a detection). Null on runs made before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('role_boundary')->nullable()->after('prohibited_capability');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('role_boundary');
        });
    }
};
