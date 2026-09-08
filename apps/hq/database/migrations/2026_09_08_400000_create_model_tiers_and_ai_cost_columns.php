<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, milestone M4: the Model Router and AI cost accounting (docs/48 J, ADR-019, docs/52).
 *
 * `model_tiers` is the reason no model id appears in business code. A price change, a retirement or
 * a better model is a row edit here, not a deploy, and because every call is chosen from these rows
 * the cost of a call can be computed from the same place that selected it.
 *
 * `ai_runs` gains three columns. `actual_cost_usd` and `cost_source` exist because an estimate from
 * token counts and a provider's own billing figure disagree, and a cost report that quietly mixes
 * the two is a report nobody can reconcile later. `degraded_from_tier` exists so that a downgrade is
 * visible in the data rather than only in a log line, which is what lets a briefing say on its face
 * that it ran on a weaker model.
 *
 * `ai_budgets` holds the caps. Per decision V.8 the shipped cap is SOFT: it downgrades and notifies
 * rather than refusing, because a cap that silently stops the morning briefing is a worse failure
 * than the overspend it prevents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_tiers', function (Blueprint $table) {
            $table->id();

            // What band of work this row can serve, and who serves it.
            $table->string('tier', 16);
            $table->string('provider', 32);
            $table->string('model_id', 128);
            $table->string('display_name', 128);

            // What it can do. `capabilities` is a list of strings a RoutingContext can require.
            $table->unsignedInteger('context_window');
            $table->unsignedInteger('max_output_tokens');
            $table->jsonb('capabilities')->default(DB::raw("'[]'::jsonb"));

            // What it costs, per million tokens, in USD. Decimal because money is never a float.
            $table->decimal('input_price_per_mtok', 10, 4);
            $table->decimal('output_price_per_mtok', 10, 4);
            $table->decimal('cache_read_price_per_mtok', 10, 4)->nullable();
            $table->decimal('cache_write_price_per_mtok', 10, 4)->nullable();

            // Lower priority wins within a tier. A tie is broken by cheaper input price, so an
            // unranked row can never accidentally become the expensive default.
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);

            // Why this row exists and where its numbers came from, so a future reader can re-check
            // them against the provider's page rather than trusting the row.
            $table->text('notes')->nullable();
            $table->date('pricing_verified_on')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'model_id', 'tier']);
            $table->index(['tier', 'is_active', 'priority']);
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            // Separate from `estimated_cost_usd`, never overwriting it: keeping both is what makes a
            // later reconciliation against an invoice possible.
            $table->decimal('actual_cost_usd', 12, 6)->nullable()->after('estimated_cost_usd');
            $table->string('cost_source', 24)->nullable()->after('actual_cost_usd');

            // Cache WRITES are billed at a premium over base input, so a run that does not record
            // them cannot re-derive its own cost. `cached_tokens` (reads) has existed since Phase 0;
            // this is its other half.
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('cached_tokens');

            // Null means the run got the tier it asked for.
            $table->string('degraded_from_tier', 16)->nullable()->after('model_tier');

            // Which attempt produced this run, and why an earlier one did not. A fallback that
            // leaves no trace is indistinguishable from a first-choice success.
            $table->unsignedSmallInteger('attempt')->default(1)->after('status');
            $table->string('routing_reason', 160)->nullable()->after('degraded_from_tier');

            // ⚠ NOT ['business_id', 'created_at']: that index has existed since the Phase 0 ai_runs
            // migration. Adding it again is a duplicate-relation error on a fresh database.
            $table->index(['created_at', 'provider']);
            $table->index(['agent_key', 'created_at']);
        });

        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();

            // What the cap applies to: a business, an agent, or the whole group.
            $table->string('scope_type', 16);              // global | business | agent
            $table->string('scope_ref', 64)->nullable();   // business id or agent key; null for global
            $table->string('period', 16);                  // daily | monthly

            $table->decimal('limit_usd', 12, 4);

            // ⚠ V.8: `soft` downgrades the tier and notifies; `hard` refuses and notifies. Phase 2
            // ships soft only. A hard cap is a business decision, not an engineering default.
            $table->string('enforcement', 8)->default('soft');
            $table->unsignedSmallInteger('notify_at_percent')->default(80);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_ref', 'period']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // A cost that was never computed is null; a cost that was computed is never negative.
            DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_costs_non_negative CHECK ((estimated_cost_usd IS NULL OR estimated_cost_usd >= 0) AND (actual_cost_usd IS NULL OR actual_cost_usd >= 0))');

            // An actual cost must say where it came from, or it cannot be reconciled.
            DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_actual_cost_has_source CHECK ((actual_cost_usd IS NULL) = (cost_source IS NULL))');

            DB::statement("ALTER TABLE ai_budgets ADD CONSTRAINT ai_budgets_enforcement CHECK (enforcement IN ('soft', 'hard'))");
            DB::statement("ALTER TABLE ai_budgets ADD CONSTRAINT ai_budgets_period CHECK (period IN ('daily', 'monthly'))");
            DB::statement("ALTER TABLE ai_budgets ADD CONSTRAINT ai_budgets_scope CHECK (scope_type IN ('global', 'business', 'agent') AND (scope_type = 'global') = (scope_ref IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_costs_non_negative');
            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_actual_cost_has_source');
        }

        Schema::dropIfExists('ai_budgets');

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'provider']);
            $table->dropIndex(['agent_key', 'created_at']);
            $table->dropColumn(['actual_cost_usd', 'cost_source', 'cache_write_tokens', 'degraded_from_tier', 'attempt', 'routing_reason']);
        });

        Schema::dropIfExists('model_tiers');
    }
};
