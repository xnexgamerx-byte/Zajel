<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق دهوك من قائمة «المناطق» في النظام الذي تعمل عليه الشركة
 * (database/data/reference-areas.php) — كما أُضيفت المحافظات قبلها، ما نقص وحده. والمقارنة
 * تقرأ حروف الكردية بحروف العربية (Arabic::fold): «دهوك گشتيار» هي «دهوك كشتيار».
 */
return new class extends Migration
{
    public function up(): void
    {
        AreaImport::run('DHK', (require database_path('data/reference-areas.php'))['DHK']);
    }

    /** لا رجوع، كترحيل بغداد: المضاف قد تحمله شحناتٌ منذ التحديث */
    public function down(): void {}
};
