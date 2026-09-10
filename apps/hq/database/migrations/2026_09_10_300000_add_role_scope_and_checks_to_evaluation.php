<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation becomes role-aware, and each check inside a case becomes visible on its own (ADR-035,
 * ADR-036).
 *
 * ## Why
 *
 * The first real gate run measured the executive agent against twelve cases every one of which is
 * framed as a customer-support situation - "Answer the customer" eight times, "Act on the request"
 * three times. It scored 0.333 and **not one forbidden tool was called in any of them** (docs/61).
 * Those two facts describe very different systems, and one number could only show the first.
 *
 * Two columns fix two different halves of that:
 *
 * - **role scope** so a case is only graded against an agent whose role makes its expectation
 *   meaningful. An executive agent that will not answer a customer is behaving correctly, not
 *   failing.
 * - **per-check outcomes** so `must_not_call` passing 12 of 12 stays visible whatever the case
 *   verdict says. A grader that returns one boolean can only ever report the first thing that went
 *   wrong.
 *
 * ⚠ Every column is nullable or defaulted. `role_key`/`applies_to_role` null means "every role",
 * which is what every existing suite and case means today, so nothing is re-scoped by this
 * migration alone. The seeder does the tagging, deliberately and visibly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_suites', function (Blueprint $table) {
            // null = a cross-agent suite. Set = this suite is only meaningful for that role.
            $table->string('role_key', 32)->nullable()->after('kind');
        });

        Schema::table('evaluation_cases', function (Blueprint $table) {
            // Narrows within a suite: a global suite may still hold a case whose framing only makes
            // sense for one role. Null = every role the suite applies to.
            $table->string('applies_to_role', 32)->nullable()->after('dimension');
            $table->index(['suite_id', 'applies_to_role']);
        });

        Schema::table('evaluation_results', function (Blueprint $table) {
            // {check => pass|fail|not_applicable}. The per-check truth behind one verdict.
            $table->jsonb('checks')->nullable()->after('dimension');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            // {check => {passed, failed, rate}} aggregated across the run, so a check that held
            // everywhere is reportable even when the run failed.
            $table->jsonb('check_rates')->nullable()->after('dimension_rates');
            // How many active cases this run did NOT grade because they belong to another role.
            // ⚠ Recorded rather than inferred: "12 cases, 0 graded" and "0 cases" must never look
            // the same, or a suite that matched nothing would read as a suite that passed.
            $table->unsignedInteger('cases_skipped')->default(0)->after('cases_failed');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_runs', function (Blueprint $t) {
            $t->dropColumn(['check_rates', 'cases_skipped']);
        });
        Schema::table('evaluation_results', fn (Blueprint $t) => $t->dropColumn('checks'));
        Schema::table('evaluation_cases', function (Blueprint $t) {
            $t->dropIndex(['suite_id', 'applies_to_role']);
            $t->dropColumn('applies_to_role');
        });
        Schema::table('evaluation_suites', fn (Blueprint $t) => $t->dropColumn('role_key'));
    }
};
