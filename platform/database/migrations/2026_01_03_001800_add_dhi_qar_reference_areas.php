<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق ذي قار من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php) — كما أُضيفت المحافظات قبلها، ما نقص وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('DHQ', (require database_path('data/reference-areas.php'))['DHQ']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
