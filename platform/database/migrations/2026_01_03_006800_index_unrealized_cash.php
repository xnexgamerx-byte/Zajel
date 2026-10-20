<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لوحة الأرباح على ١٦٥ ألف شحنة (docs/plan/68 م٧): «غير المحصَّل» كان يمسح شحنات الشركة كلّها.
 * - بيد المناديب: الراجعة والملغاة لا كشف مندوبٍ لها أبداً، فـ«بلا كشف» وحده يحمل التاريخ كلّه؛
 *   والتاريخ بعده يحصره في المسلَّمة.
 * - على التجّار: «بلا كشف تاجر» أو «في كشفٍ لم يُدفع» بمستحقٍّ سالب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'courier_settlement_id', 'delivered_at'], 'sh_cash_open_idx');
            $table->index(['company_id', 'merchant_settlement_id', 'merchant_due'], 'sh_merchant_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_cash_open_idx');
            $table->dropIndex('sh_merchant_due_idx');
        });
    }
};
