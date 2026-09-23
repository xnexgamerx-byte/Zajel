<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس البحث وتقادُم النقد — قيست على MySQL 8 بـ ١٦٥ ألف شحنة.
 *
 * البحث (رقم وصل، باركود، رقم طلب، هاتف، هاتف بديل، بداية الاسم) كان
 * «أو» واحدة، وثلاثٌ من فروعها بلا فهرس، فيمسح MySQL شحنات الشركة
 * كلّها مرّتين (العدّ ثم الصفحة): ٤٥٠ مللي ثانية لكل بحث، تكبر مع كل
 * يوم. صار اتّحادَ بحثٍ في كل فهرسٍ على حدة (Shipment::scopeSearch):
 * ١٠ مللي ثانية.
 *
 * وأقدم نقدٍ لم يُسوَّ في لوحة اليوم كان يقرأ كل شحنةٍ بلا تسوية مندوب
 * — والراجعة والملغاة تبقى كذلك إلى الأبد — ٧٢ ألف صفّ: ٧٣ مللي ثانية.
 * بإضافة delivered_at إلى الفهرس صار يقرأ المسلَّمة غير المسوّاة وحدها
 * (ألفٌ تقريباً، يحدّها العمل لا التاريخ): ٣.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'merchant_reference'], 'sh_reference_idx');
            $table->index(['company_id', 'recipient_phone_alt'], 'sh_phone_alt_idx');
            $table->index(['company_id', 'recipient_name'], 'sh_name_idx');

            $table->dropIndex('sh_csettle_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'courier_settled_at', 'delivered_at'], 'sh_csettle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_reference_idx');
            $table->dropIndex('sh_phone_alt_idx');
            $table->dropIndex('sh_name_idx');
            $table->dropIndex('sh_csettle_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'courier_settled_at'], 'sh_csettle_idx');
        });
    }
};
