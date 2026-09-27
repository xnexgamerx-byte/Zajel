<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول «متاجر الفرع» و«موظّفو الفرع» في المعتاد (docs/plan/17 §٣ · ١١):
 *
 * نوع البضاعة، والعميل المميّز، و«يُسمح له بالدخول للنظام»، ومندوب الاستلام
 * الذي يخدمه عادةً، وموظّف المبيعات الذي جاء به — و«هل موظّف مبيعات؟» للموظّف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('goods_type', 40)->nullable()->after('business_name');
            $table->boolean('is_vip')->default(false)->after('goods_type');
            $table->boolean('portal_access')->default(true)->after('status');
            $table->foreignId('pickup_courier_id')->nullable()->after('branch_id')->constrained('couriers')->nullOnDelete();
            $table->foreignId('sales_user_id')->nullable()->after('pickup_courier_id')->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'is_vip'], 'mer_vip_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sales')->default(false)->after('rank_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_sales');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropIndex('mer_vip_idx');
            $table->dropConstrainedForeignId('sales_user_id');
            $table->dropConstrainedForeignId('pickup_courier_id');
            $table->dropColumn(['goods_type', 'is_vip', 'portal_access']);
        });
    }
};
