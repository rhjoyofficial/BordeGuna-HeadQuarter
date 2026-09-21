<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/81 P0-4: an approval keeps the requester, the enabler, the acting identity and the approver
 * apart.
 *
 * `requested_by_user_id`, `agent_key` and `decided_by_user_id` already hold three of the four. What
 * was missing is the ENABLING user of the agent that parked the call, which until now was written
 * into the requester's column, and the resolved execution context (ADR-047) the call ran under.
 *
 * Additive and nullable. No existing row is rewritten: a row from before this has nulls here, and
 * a null `enabled_by_user_id` is how a reader knows the row predates the split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('enabled_by_user_id')->nullable()->after('requested_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('requester_kind', 24)->nullable()->after('enabled_by_user_id');
            $table->string('execution_context_digest', 64)->nullable()->after('requester_kind');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enabled_by_user_id');
            $table->dropColumn(['requester_kind', 'execution_context_digest']);
        });
    }
};
