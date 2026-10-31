<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruling 31 Oct 2026 item 12 (docs/147-r2): the per-business monthly media spending cap, in BDT, and its ledger. Also item 10: the
 * column holding a reviewer's field-by-field confirmations on a supplier document.
 *
 * ⛔ Seeds NOTHING. A business with no `media_budgets` row has a limit of BDT 0, and BDT 0 means no paid media generation or
 * operation may run. Only the owner sets a limit (screen, audited); no tool, job or agent can. The ledger keeps the ESTIMATE
 * reserved before a provider is called and the ACTUAL the provider reported, side by side, never one overwriting the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            // BDT, two decimals. 0 = no paid media.
            $table->decimal('monthly_limit_bdt', 14, 2)->default(0);
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('media_budget_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // Asia/Dhaka business month, YYYY-MM.
            $table->string('period', 7);
            $table->string('kind', 16);
            $table->string('provider', 64);
            $table->string('tool_key', 64);
            // What the reservation is for (the gateway's idempotency key): one reservation per reference per business.
            $table->string('reference', 191);
            // The estimate, in the provider's currency and converted (conservatively) to BDT.
            $table->decimal('estimate_amount', 14, 4);
            $table->string('estimate_currency', 3);
            $table->decimal('fx_rate_to_bdt', 14, 6);
            $table->decimal('estimate_bdt', 14, 2);
            // What the provider reported, kept beside the estimate (null until reconciled).
            $table->decimal('actual_amount', 14, 4)->nullable();
            $table->string('actual_currency', 3)->nullable();
            $table->decimal('actual_bdt', 14, 2)->nullable();
            $table->string('state', 16);
            $table->string('basis', 255)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'period', 'state']);
        });

        // Ruling 31 Oct item 10: the fields a reviewer confirmed, one by one, before a supplier document could be marked reviewed.
        Schema::table('ingested_documents', function (Blueprint $table) {
            $table->jsonb('review_confirmations')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE media_budgets ADD CONSTRAINT media_budgets_limit_nonnegative CHECK (monthly_limit_bdt >= 0)');
            DB::statement("ALTER TABLE media_budget_ledger ADD CONSTRAINT media_budget_ledger_state CHECK (state IN ('reserved','committed','released','reconciled'))");
            DB::statement("ALTER TABLE media_budget_ledger ADD CONSTRAINT media_budget_ledger_kind CHECK (kind IN ('video','image','voice','avatar','other'))");
            DB::statement('ALTER TABLE media_budget_ledger ADD CONSTRAINT media_budget_ledger_amounts CHECK (estimate_amount >= 0 AND estimate_bdt >= 0 AND fx_rate_to_bdt > 0 AND (actual_bdt IS NULL OR actual_bdt >= 0))');
            DB::statement("ALTER TABLE media_budget_ledger ADD CONSTRAINT media_budget_ledger_period CHECK (period ~ '^[0-9]{4}-[0-9]{2}$')");
        }
    }

    public function down(): void
    {
        Schema::table('ingested_documents', function (Blueprint $table) {
            $table->dropColumn('review_confirmations');
        });
        Schema::dropIfExists('media_budget_ledger');
        Schema::dropIfExists('media_budgets');
    }
};
