<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the model stopped, and how much room it had (ADR-038).
 *
 * The third safety gate diagnosed a `model_error` from the token counts alone: 8,192 output tokens
 * against a 4,096 cap could only mean a cut-off reply. That reading should never have to be
 * inferred. `stop_reason` records the provider's own account of why the reply ended, in the
 * driver's neutral vocabulary (`end_turn`, `max_tokens`, ...), and `max_output_tokens` records the
 * budget the call was actually sent with, after the policy clamped it - so a row can say
 * "truncated at 8,192" rather than leaving a reader to subtract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('stop_reason', 32)->nullable()->after('status');
            $table->unsignedInteger('max_output_tokens')->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['stop_reason', 'max_output_tokens']);
        });
    }
};
