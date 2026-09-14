<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-042: the runtime's own reading of the trusted message for security-sensitive intent, recorded
 * on every run beside the model's field. `security_intent` holds the detector version, the classes
 * matched and the clauses they came from; `authority_source` says who set the authority state:
 * `runtime` (the detector, which the model cannot lower), `model` (only the model's field), `none`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('security_intent')->nullable()->after('authority_request');
            $table->string('authority_source', 16)->default('none')->after('security_intent');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['security_intent', 'authority_source']);
        });
    }
};
