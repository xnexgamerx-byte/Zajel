<?php

use App\Support\Arabic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مناطق المحافظات السبع عشرة غير بغداد (database/data/governorate-areas.php)
 * للخوادم القائمة — كما وصلت مناطق بغداد بترحيلها.
 *
 * ترحيلٌ لا بذرة: الخادم يُرحِّل مع كل تحديث ولا يبذر إلّا عند التثبيت. والتثبيت
 * الجديد لا محافظات فيه وقت الترحيل، فلا يجد شيئاً هنا ويجدها GovernorateSeeder.
 * ولا يُكرَّر موجود: المقارنة بالاسم المطويّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $governorates = DB::table('governorates')->pluck('id', 'code');
        $now = now();

        foreach (require database_path('data/governorate-areas.php') as $code => $names) {
            $governorateId = $governorates[$code] ?? null;

            if (! $governorateId) {
                continue;
            }

            $existing = DB::table('cities')->where('governorate_id', $governorateId)->pluck('name_ar')
                ->mapWithKeys(fn ($name) => [Arabic::fold($name) => true])
                ->all();

            $rows = [];

            foreach ($names as $name) {
                if (isset($existing[Arabic::fold($name)])) {
                    continue;
                }

                $existing[Arabic::fold($name)] = true;
                $rows[] = [
                    'governorate_id' => $governorateId,
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
    }

    /**
     * لا رجوع: منطقةٌ أُضيفت قد تحملها شحناتٌ ومناطق مندوبين وتسعيرات منذ
     * التحديث، وحذفها يمحو عناوين تلك الشحنات.
     */
    public function down(): void {}
};
