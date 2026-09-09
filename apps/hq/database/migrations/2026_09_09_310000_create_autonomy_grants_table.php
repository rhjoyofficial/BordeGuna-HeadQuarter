<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autonomy grants: the constraint lattice the resolver reads (ADR-020, Phase 2.5 C).
 *
 * One column on `agents` cannot say "may answer order-status questions on Messenger for returning
 * customers during business hours, but never on WhatsApp, never about refunds, and never above five
 * thousand taka". Twelve dimensions can.
 *
 * ⛔ **Every dimension column is nullable, and null means "this grant does not care about that
 * dimension" - it is a WILDCARD on the matching side, never a permission.** A grant matches a
 * request when every non-null dimension it names agrees. The effective level is then the MINIMUM
 * `max_level` across all matching grants, and no matching grant at all means A0.
 *
 * That is the opposite default to the `tool_registrations` table, where an absent row means enabled,
 * and the difference is deliberate: an absent tool row means a capability the deployed code already
 * contains and a human already reviewed, while an absent autonomy grant means a combination nobody
 * has evaluated.
 *
 * Grants are versioned and superseded rather than edited, the way `policies` already are, so the
 * question "what was this agent allowed to do on the day it did that" stays answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomy_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('reason')->nullable();

            // ── The dimensions. All nullable; null = applies to any value of this dimension.
            $table->string('agent_key', 64)->nullable();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->string('channel', 24)->nullable();
            $table->string('tool_key', 64)->nullable();
            $table->string('action_class', 24)->nullable();     // read|write|human_only...
            $table->string('customer_segment', 32)->nullable(); // new|returning|vip|at_risk
            $table->string('environment', 16)->nullable();      // production|staging|local
            $table->string('data_class', 24)->nullable();

            /** The highest monetary band this grant covers. A call above it does not match. */
            $table->string('max_monetary_impact', 16)->nullable(); // none|low|medium|high

            /**
             * Time bounds, as {"timezone":"Asia/Dhaka","days":[0..6],"from":"09:00","to":"18:00"}.
             * Business hours are a real autonomy dimension: the same action is a different risk at
             * 03:00 when nobody would notice it for six hours.
             */
            $table->jsonb('time_window')->nullable();

            /**
             * Evaluation confidence: the measured agreement rate this grant requires before it
             * applies at all (ADR-023). A grant naming 0.95 simply does not match until the
             * evaluation evidence for that combination reaches 0.95, which is what makes autonomy
             * earned rather than granted.
             */
            $table->decimal('min_agreement_rate', 4, 3)->nullable();
            $table->string('evaluation_suite_key', 64)->nullable();

            // ── The grant itself.
            $table->string('max_level', 4);   // a0..a5
            $table->string('status', 16)->default('active'); // active|superseded|revoked
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('autonomy_grants')->nullOnDelete();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'agent_key']);
            $table->index(['status', 'business_id']);
            $table->index(['status', 'tool_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonomy_grants');
    }
};
