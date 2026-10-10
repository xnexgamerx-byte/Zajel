<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عمولة الفرع (docs/plan/51): ما اتّفقت الشركة عليه مع فرعٍ عن كلّ طلبٍ يوصّله مناديبه —
 * ٣٥٠٠ مثلاً — يدفع منها عمولة مندوبه (٢٠٠٠) ويبقى له الفرق (١٥٠٠).
 *
 * تُجمَّد على الشحنة ساعة التسليم كعمولة المندوب: الفرع الموصِّل (delivery_branch_id)
 * وما يستحقّه عنها (branch_commission)، فلا يغيّر تعديلُ النسبة غداً حسابَ ما مضى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('commission_per_delivery')->default(0)->after('price_list_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('delivery_branch_id')->nullable()->after('delivery_courier_id')
                ->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('branch_commission')->default(0)->after('courier_commission');
            $table->index(['company_id', 'delivery_branch_id', 'delivered_at'], 'sh_dbranch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_dbranch_idx');
            $table->dropConstrainedForeignId('delivery_branch_id');
            $table->dropColumn('branch_commission');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('commission_per_delivery');
        });
    }
};
