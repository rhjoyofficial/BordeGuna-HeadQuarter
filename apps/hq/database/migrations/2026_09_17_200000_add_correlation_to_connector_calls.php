<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link docs/48 N.1 draws from a tool call to what left the building (docs/78 E).
 *
 * The gateway has always scoped a run's correlation id into the context for the whole of a tool's
 * execution; the connector logger never wrote it down, so a trace from a tool call could not reach its
 * connector calls. Nullable: rows recorded before this, and calls made outside any gateway call (a
 * console probe), have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_calls', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('connector_calls', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn('correlation_id');
        });
    }
};
