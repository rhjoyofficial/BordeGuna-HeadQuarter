<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, milestone M1: the control-plane foundation (docs/48, docs/49).
 *
 * Three tables, all additive, none of them load-bearing for anything already running:
 *
 * - `tools`                is the RUNTIME kill switch. It can only ever NARROW what
 *                          config('hq.tools') already registered. The absence of a row means
 *                          "enabled", so a tool deployed in code works before anyone touches this
 *                          table, and no ordering problem exists between a deploy and a sync.
 * - `user_business_scopes` narrows a human to some businesses. NO ROWS MEANS GROUP-WIDE, so every
 *                          account that exists today keeps exactly the access it has today.
 * - `agents`               is the identity foundation. M1 creates no agent rows and runs no agent;
 *                          the table exists so ActingIdentity has something real to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The runtime half of the two-switch model (docs/48 D.2). config('hq.tools') is the other
        // half and always wins: a row here can disable a registered tool, never invent one.
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->jsonb('enabled_businesses')->nullable(); // null = every business the tool supports
            $table->text('disabled_reason')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestamps();
        });

        // ADR-010 narrowed to what one operator actually needs (docs/48 E.4). Deliberately NOT
        // spatie's teams feature: that would re-key every existing permission check for a
        // requirement that today has a single user.
        Schema::create('user_business_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('access', 16)->default('read'); // read|manage
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });

        // Agents are configuration, not code (docs/08). One runtime will execute all of them.
        // `enabling_user_id` is the human whose authority an agent borrows: effective permission is
        // the INTERSECTION of the two, never a union, and there is no wildcard (docs/48 E.3).
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->string('status', 16)->default('paused'); // active|paused|retired - default paused, fail closed
            $table->foreignId('enabling_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_key', 32)->nullable();      // executive|analytics|...
            $table->jsonb('allowed_tools')->nullable();      // the MAXIMUM set, not the effective one
            $table->jsonb('business_scope')->nullable();     // null = every business its human can see
            $table->jsonb('brand_scope')->nullable();
            $table->jsonb('memory_scope')->nullable();
            $table->string('model_tier', 16)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('max_autonomy_level', 4)->default('a0'); // a0 shadow (Joy, V.2)
            $table->decimal('daily_cost_cap_usd', 10, 4)->nullable();
            $table->unsignedInteger('daily_call_cap')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
        Schema::dropIfExists('user_business_scopes');
        Schema::dropIfExists('tools');
    }
};
