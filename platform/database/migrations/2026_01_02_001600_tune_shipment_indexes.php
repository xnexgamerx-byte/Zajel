<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس اللوحات.
 *
 * (company_id, deleted_at) الذي أضافه ترحيل «شحنات ممسوحة» يطابق كل صفٍّ حيّ
 * (deleted_at فارغٌ في كل شحنةٍ تقريباً)، ومخطّط SQLite بلا إحصاءات يختاره لكل
 * استعلامٍ على الشحنات بدل فهرسه الحقيقي: «مشتبه بتكرارها» صار يمسح الشركة كلّها
 * بدل sh_duplicate_idx. والسلّة تُفتح نادراً وتكفيها القراءة بلا فهرسها.
 *
 * و(… status, merchant_settled_at): «الواصل» في «كل مراحل النقل» ما سُلّم ولم
 * يُحاسَب عليه التاجر — قليلٌ من كثير، وكان كل عدّادٍ منه يقرأ الواصل كلّه: ٣٠
 * مللي ثانية لكلٍّ من ثلاثة على ١٦٥ ألف شحنة، والآن واحدة. وعلى نسق فهارس
 * الحجم (deleted_at ثانياً) فيختاره MySQL مع شرط الحذف الناعم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasIndex('shipments', 'sh_deleted_idx')) {
                $table->dropIndex('sh_deleted_idx');
            }

            $table->index(['company_id', 'deleted_at', 'status', 'merchant_settled_at'], 'sh_unsettled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_unsettled_idx');
            $table->index(['company_id', 'deleted_at'], 'sh_deleted_idx');
        });
    }
};
