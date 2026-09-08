<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, milestone M2: the real policy engine (docs/48 F, docs/50).
 *
 * A policy is a VERSIONED, TIME-BOUNDED row. Changing a rule never mutates a row: it closes the
 * current version and opens a new one. That is what makes a past decision reproducible, and a
 * decision that cannot be reproduced later is not an audit trail, it is a rumour.
 *
 * Selection is by specificity, most specific first:
 *     tool + business  >  tool  >  business  >  global
 * The FIRST match wins and its rules are the whole rule set. Merging several policies would make
 * "which rule decided this" a question with a paragraph for an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96);
            $table->unsignedInteger('version')->default(1);

            // Both nullable: null means "any". A row with both null is the global default.
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('tool_key', 64)->nullable();

            $table->jsonb('rules');
            $table->text('note')->nullable();

            // The active window. active_to null means "still in force". A superseded row keeps its
            // rules verbatim so a historical tool_call can be explained exactly as it was decided.
            $table->timestampTz('active_from')->useCurrent();
            $table->timestampTz('active_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['tool_key', 'business_id', 'active_from']);
            $table->index(['active_from', 'active_to']);
        });

        Schema::table('tool_calls', function (Blueprint $table) {
            // Which policy row decided this call, and which version of it. Nullable because a call
            // refused before policy runs (an unknown tool, invalid arguments) never reaches one,
            // and because the tier baseline alone decides when no policy row matches.
            $table->foreignId('policy_id')->nullable()->after('policy_reason')->constrained('policies')->nullOnDelete();
            $table->unsignedInteger('policy_version')->nullable()->after('policy_id');
            $table->string('action_class', 24)->nullable()->after('policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('policy_id');
            $table->dropColumn(['policy_version', 'action_class']);
        });

        Schema::dropIfExists('policies');
    }
};
