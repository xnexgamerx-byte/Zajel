<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منطقةٌ تضيفها الشركة لنفسها (AreaController::store).
 *
 * المنطقة صارت إلزامية في الشحنة (docs/plan/20 §٣)، والقائمة — مهما طالت —
 * تنقصها حارةٌ جديدة أو قريةٌ صغيرة؛ وبلا إضافتها لا تُحفظ شحنةٌ إليها ويُرفض
 * صفّ Excel بها. company_id فارغٌ للمناطق العامة التي يراها الجميع، ورقم
 * الشركة لما أضافته هي: تراه وحدها، فلا يصل خطأ كتابةِ شركةٍ إلى غيرها.
 *
 * والاسم فريدٌ في المحافظة لكل شركة: شركتان تضيفان الحارة نفسها كلٌّ لنفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('governorate_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique(['governorate_id', 'name_ar']);
            $table->unique(['governorate_id', 'company_id', 'name_ar']);
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique(['governorate_id', 'company_id', 'name_ar']);
            $table->unique(['governorate_id', 'name_ar']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
