<?php

use App\Support\AreaImport;
use Illuminate\Database\Migrations\Migration;

/**
 * مناطق المحافظات من قائمة «المناطق» في النظام الذي تعمل عليه الشركة اليوم
 * (database/data/reference-areas.php) — بغداد أوّلاً.
 *
 * ترحيلٌ لا بذرة: الخادم يُرحِّل مع كل تحديث ولا يبذر إلّا عند التثبيت. والتثبيت الجديد
 * لا محافظات فيه وقت الترحيل، فلا يجد شيئاً هنا ويجدها GovernorateSeeder. ومنطقةٌ كانت
 * شركةٌ أضافتها لنفسها بالاسم نفسه تُضمّ إلى العامّة (AreaImport).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (require database_path('data/reference-areas.php') as $code => $names) {
            AreaImport::run($code, $names);
        }
    }

    /**
     * لا رجوع: منطقةٌ أُضيفت قد تحملها شحناتٌ ومناطق مندوبين وتسعيرات منذ
     * التحديث، وحذفها يمحو عناوين تلك الشحنات.
     */
    public function down(): void {}
};
