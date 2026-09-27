<?php

use App\Support\Arabic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مناطق بغداد الـ٣٥٥ (database/data/baghdad-areas.php) للخوادم القائمة.
 *
 * ترحيلٌ لا بذرة: الخادم يُرحِّل مع كل تحديث ولا يبذر إلّا عند التثبيت، والبذرة
 * تُعيد الباقات إلى أسعارها الأولى. والتثبيت الجديد لا محافظات فيه وقت الترحيل،
 * فيتخطّاه ويجدها GovernorateSeeder.
 *
 * لا يُكرَّر موجود: المقارنة بالاسم المطويّ، فـ«الأعظمية» عندنا هي «الاعظمية»
 * في القائمة. و«الرشيدية» تصير «الراشدية» — تلك في نينوى، وهذه في بغداد —
 * بمعرّفها، فتبقى الشحنات والمناطق المسندة إليها كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        $baghdad = DB::table('governorates')->where('code', 'BGD')->value('id');

        if (! $baghdad) {
            return;
        }

        $taken = fn () => DB::table('cities')->where('governorate_id', $baghdad)->pluck('name_ar')
            ->mapWithKeys(fn ($name) => [Arabic::fold($name) => true]);

        if (! $taken()->has(Arabic::fold('الراشدية'))) {
            DB::table('cities')->where('governorate_id', $baghdad)->where('name_ar', 'الرشيدية')
                ->update(['name_ar' => 'الراشدية', 'updated_at' => now()]);
        }

        $existing = $taken()->all();
        $now = now();
        $rows = [];

        foreach (require database_path('data/baghdad-areas.php') as $name) {
            if (isset($existing[Arabic::fold($name)])) {
                continue;
            }

            $existing[Arabic::fold($name)] = true;
            $rows[] = [
                'governorate_id' => $baghdad,
                'name_ar'        => $name,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('cities')->insert($chunk);
        }
    }

    /**
     * لا رجوع: منطقةٌ أُضيفت قد تحملها شحناتٌ ومناطق مندوبين وتسعيرات منذ
     * التحديث، وحذفها يمحو عناوين تلك الشحنات.
     */
    public function down(): void {}
};
