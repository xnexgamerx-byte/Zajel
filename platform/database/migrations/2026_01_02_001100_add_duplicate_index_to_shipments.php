<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شاشة المشتبه بتكرارها: ما عُلِّم ولم يُمسَح.
 *
 * بلا فهرسٍ على duplicate_of_id كان MySQL يقرأ شحنات الشركة كلّها ليجد
 * بضع عشرات: ٤٣٢ مللي ثانية على ١٦٥ ألفاً. «لم يُمسَح» مساواةٌ على
 * duplicate_cleared_at ثم «عُلِّم» مدىً على duplicate_of_id، فيقرأ
 * الفهرس المشتبهاتِ المفتوحة وحدها: ٤.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'duplicate_cleared_at', 'duplicate_of_id'], 'sh_duplicate_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_duplicate_idx');
        });
    }
};
