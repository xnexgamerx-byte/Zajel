<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق الأنبار من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php): الرمادي وغربها، والفلوجة وما حولها — كما أُضيفت
 * بغداد وكربلاء، ما نقص وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('ANB', (require database_path('data/reference-areas.php'))['ANB']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
