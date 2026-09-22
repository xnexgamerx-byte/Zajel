<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس القياس — قيست على ١٨٠ ألف شحنة (سنة عند ٥٠٠ يومياً).
 *
 * الفهارس القائمة لم تُغطِّ عمود الحذف الناعم، فكان كل تجميع بالحالة
 * يزور صفوف الجدول واحداً واحداً: ٢٨١ مللي ثانية. وبفهرس يشمل
 * deleted_at و cod_amount صار الاستعلام يُقرأ من الفهرس وحده: ٢١.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // لوحة اليوم وقائمة الشحنات: عدّ ومجموع بالحالة من الفهرس وحده
            $table->index(['company_id', 'deleted_at', 'status', 'cod_amount'], 'sh_status_cover_idx');

            // تقارير الحركة اليومية والأرباح
            $table->index(['company_id', 'delivered_at'], 'sh_delivered_idx');
            $table->index(['company_id', 'returned_at'], 'sh_returned_idx');

            // شاشة المناطق: ما ينتظر في كل محافظة
            $table->index(['company_id', 'deleted_at', 'governorate_id', 'status'], 'sh_gov_open_idx');

            // المتعثّرة والمفتوحة: حالة ثم مدى زمنيّ من الفهرس وحده
            $table->index(['company_id', 'deleted_at', 'status', 'status_changed_at'], 'sh_open_age_idx');

            // عدّ شحنات كل تاجر في قائمة التجّار
            $table->index(['company_id', 'deleted_at', 'merchant_id'], 'sh_merchant_count_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach (['sh_status_cover_idx', 'sh_delivered_idx', 'sh_returned_idx', 'sh_gov_open_idx',
                      'sh_open_age_idx', 'sh_merchant_count_idx'] as $index) {
                $table->dropIndex($index);
            }
        });
    }
};
