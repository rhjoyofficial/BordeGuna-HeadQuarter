<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/90 A.6 (stream V2): the operator's language preference is the person's, not the browser's.
 *
 * One nullable column. Null means "no preference saved yet", and the screen then falls back to the
 * browser's language and finally to English (docs/90 A.6 hierarchy). It is written only by the person
 * themselves through their profile settings, audited, and no model, provider or tool can reach it.
 *
 * Additive: nothing dropped, renamed or changed; no default that rewrites a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
