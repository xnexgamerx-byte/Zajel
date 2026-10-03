<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق كربلاء من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php) — كما أُضيفت بغداد (2026_01_03_000100): ما نقص
 * وحده، ومنطقةٌ أضافتها شركةٌ لنفسها بالاسم نفسه تُضمّ إلى العامّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('KRB', (require database_path('data/reference-areas.php'))['KRB']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
