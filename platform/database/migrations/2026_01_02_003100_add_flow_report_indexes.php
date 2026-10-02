<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس تقارير الدفق (FlowReportController) — قيست على ١٦٥ ألف شحنة ومليون حدث.
 *
 * «المستلمة من مندوب الاستلام» و«أداؤهم» بتاريخ الاستلام ولا فهرس له: كل طلبٍ
 * مسح شحنات الشركة كلّها (٣٠٦ مللي ثانية لشهر)، وبالفهرس ٣٧.
 *
 * و«تغيّرت أسعارها» يبحث في سجلّ الأحداث عن التعديلات وحدها: بلا فهرسٍ على النوع
 * بدأ المحرّك من الشحنات غير المسوّاة وزار أحداث كلٍّ منها (٤٠٠)، وبه ١٦.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'picked_up_at'], 'sh_pickup_idx');
        });

        Schema::table('shipment_events', function (Blueprint $table) {
            $table->index(['company_id', 'event_type', 'created_at'], 'se_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_events', function (Blueprint $table) {
            $table->dropIndex('se_type_time_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_pickup_idx');
        });
    }
};
