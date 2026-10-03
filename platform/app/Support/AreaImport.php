<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * مناطق عامّة تُضاف لمحافظة من قائمة (database/data/reference-areas.php) — يستدعيه ترحيل
 * بيانات، فتصل الخوادم القائمة مع التحديث.
 *
 * لا يُكرَّر موجود: المقارنة بالاسم المطويّ بلا «ال» (Arabic::looseFold)، فـ«صالحية» لا
 * تُضاف حيث «الصالحية». ومنطقةٌ كانت شركةٌ أضافتها لنفسها لأنّها نقصت القائمة، وجاءت الآن
 * عامّةً، تُضمّ إلى العامّة: شحناتها وتجّارها وفروعها ومناديبها وأجورها وتسعيراتها تشير
 * إليها، فلا ترى الشركة الاسم مرّتين في كل قائمة.
 *
 * يُعاد بلا ضرر: ما أُضيف مرّةً موجودٌ في المرّة الثانية.
 */
final class AreaImport
{
    /** ما يشير إلى منطقة: [الجدول، العمود] — وما له قيدٌ فريد يُعالَج قبل نقله */
    private const REFERENCES = [
        ['shipments', 'city_id'],
        ['merchants', 'city_id'],
        ['branches', 'city_id'],
        ['hubs', 'city_id'],
        ['price_list_rules', 'to_city_id'],
        ['courier_zones', 'city_id'],
        ['city_settings', 'city_id'],
    ];

    /**
     * @param  iterable<string>  $names
     * @return int عدد ما أُضيف
     */
    public static function run(string $governorateCode, iterable $names): int
    {
        $governorateId = DB::table('governorates')->where('code', $governorateCode)->value('id');

        // تثبيتٌ جديد لا محافظات فيه وقت الترحيل: يجدها GovernorateSeeder
        if (! $governorateId) {
            return 0;
        }

        $taken = DB::table('cities')->where('governorate_id', $governorateId)->whereNull('company_id')
            ->pluck('name_ar')->mapWithKeys(fn (string $name) => [Arabic::looseFold($name) => true])->all();

        $now = now();
        $added = [];

        foreach ($names as $name) {
            $key = Arabic::looseFold($name);

            if ($key === '' || isset($taken[$key])) {
                continue;
            }

            $taken[$key] = true;
            $added[$key] = $name;
        }

        foreach (array_chunk(array_values($added), 100) as $chunk) {
            DB::table('cities')->insert(array_map(fn (string $name) => [
                'governorate_id' => $governorateId,
                'name_ar'        => $name,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $chunk));
        }

        if ($added) {
            self::absorbCompanyCopies($governorateId, $added);
        }

        return count($added);
    }

    /** @param  array<string, string>  $added  الاسم مطويّاً ← الاسم */
    private static function absorbCompanyCopies(int $governorateId, array $added): void
    {
        $global = DB::table('cities')->where('governorate_id', $governorateId)->whereNull('company_id')
            ->whereIn('name_ar', array_values($added))->pluck('id', 'name_ar');

        // الأقدم أوّلاً: نسختان للمنطقة نفسها يبقى ما على أقدمهما من مندوبٍ وأجرة
        $copies = DB::table('cities')->where('governorate_id', $governorateId)->whereNotNull('company_id')
            ->orderBy('id')->get(['id', 'name_ar'])
            ->filter(fn ($city) => isset($added[Arabic::looseFold($city->name_ar)]));

        foreach ($copies as $copy) {
            $to = $global[$added[Arabic::looseFold($copy->name_ar)]];

            DB::transaction(function () use ($copy, $to) {
                // مندوبٌ أو إعدادٌ على النسختين معاً (شركةٌ أضافت الاسم بكتابتين): يبقى واحدٌ.
                // القيم أوّلاً: MySQL لا يحذف من جدولٍ بشرطٍ يقرأ الجدول نفسه
                $couriers = DB::table('courier_zones')->where('city_id', $to)->pluck('courier_id')->all();
                $companies = DB::table('city_settings')->where('city_id', $to)->pluck('company_id')->all();
                DB::table('courier_zones')->where('city_id', $copy->id)->whereIn('courier_id', $couriers)->delete();
                DB::table('city_settings')->where('city_id', $copy->id)->whereIn('company_id', $companies)->delete();

                foreach (self::REFERENCES as [$table, $column]) {
                    DB::table($table)->where($column, $copy->id)->update([$column => $to]);
                }

                DB::table('cities')->where('id', $copy->id)->delete();
            });
        }
    }
}
