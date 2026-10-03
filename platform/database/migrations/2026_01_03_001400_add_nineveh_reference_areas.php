<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق نينوى من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php) — كما أُضيفت المحافظات قبلها، ما نقص وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('NIN', (require database_path('data/reference-areas.php'))['NIN']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
