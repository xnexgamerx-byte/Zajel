<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق البصرة من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php): المركز وأقضيته، والزبير، والمدينة والقرنة — كما
 * أُضيفت المحافظات قبلها، ما نقص وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('BSR', (require database_path('data/reference-areas.php'))['BSR']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
