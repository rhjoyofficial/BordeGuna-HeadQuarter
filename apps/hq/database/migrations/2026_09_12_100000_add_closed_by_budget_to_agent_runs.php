<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a run's verdict came from the closing turn (docs/65): the budget was spent without an
 * answer, the model was told no tool would run again, and it answered or refused then. Kept on the
 * row so a reviewer can tell an answer given freely from one given when cornered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->boolean('closed_by_budget')->default(false)->after('stop_reason');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('closed_by_budget');
        });
    }
};
