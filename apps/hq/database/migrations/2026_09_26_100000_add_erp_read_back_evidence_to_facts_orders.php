<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence behind an order fact HQ read back from the ERP (docs/90 A.5, docs/79 C.17).
 *
 * Until now every `facts_orders` row came from a shop (a webhook or the admin list). An order HQ
 * placed in the ERP is read back after the write, and what the ERP said is now a fact too, with the
 * same discipline as any other read-side record: where it came from, which order the source means,
 * when the source said it was true, which HQ call read it, and a digest of exactly what was read,
 * so a later read-back that says the same thing writes nothing and one that differs is visible.
 *
 * ⛔ Additive: ten nullable columns, nothing dropped or changed; every existing row reads as a shop
 * fact (`source_system` null). `facts_orders` stays a read model: the ERP is the system of record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facts_orders', function (Blueprint $table) {
            $table->string('source_system', 16)->nullable()->after('source');        // shop | erp
            $table->string('provenance', 16)->nullable()->after('source_system');    // ADR-024: business for the ERP
            $table->unsignedBigInteger('erp_order_id')->nullable()->after('provenance');
            $table->string('erp_order_number', 64)->nullable()->after('erp_order_id');
            $table->string('erp_status', 32)->nullable()->after('erp_order_number');
            $table->string('erp_approval_status', 32)->nullable()->after('erp_status');
            $table->string('hq_key', 128)->nullable()->after('erp_approval_status');  // the key HQ made the order under
            $table->string('correlation_id', 128)->nullable()->after('hq_key');      // the HQ call that read it back
            $table->char('read_back_digest', 64)->nullable()->after('correlation_id');
            $table->timestampTz('read_back_at')->nullable()->after('read_back_digest'); // the ERP's own as_of
            $table->index(['business_id', 'erp_order_number'], 'facts_orders_business_erp_order_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('facts_orders', function (Blueprint $table) {
            $table->dropIndex('facts_orders_business_erp_order_number_index');
            $table->dropColumn(['source_system', 'provenance', 'erp_order_id', 'erp_order_number', 'erp_status', 'erp_approval_status',
                'hq_key', 'correlation_id', 'read_back_digest', 'read_back_at']);
        });
    }
};
