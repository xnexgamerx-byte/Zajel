<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * مناطق عامّة تُضاف لمحافظة من قائمة (database/data/reference-areas.php) — يستدعيه ترحيل
 * بيانات، فتصل الخوادم القائمة مع التحديث.
 *
 * لا يُكرَّر موجود: المقارنة بالاسم المطويّ بلا «ال» وبلا «حي» أو «منطقة» في أوّله
 * (Arabic::looseFold)، فـ«صالحية» لا تُضاف حيث «الصالحية» ولا «النقيب» حيث «حي النقيب».
 * ومنطقةٌ كانت شركةٌ أضافتها لنفسها لأنّها نقصت القائمة، وجاءت الآن
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
            self::merge($copy->id, $global[$added[Arabic::looseFold($copy->name_ar)]]);
        }
    }

    /**
     * منطقةٌ عامّة أُضيفت من قائمةٍ ثم تبيّن أنّها موجودةٌ قبلها باسمٍ يساويها («الرشاد» و«حي
     * الرشاد»): تُضمّ إلى الأقدم. لا شيء إن لم تُضف، أو لم يكن لها أقدم.
     *
     * @param  iterable<string>  $names  أسماؤها كما أُضيفت
     * @return int عدد ما ضُمّ
     */
    public static function mergeDuplicates(string $governorateCode, iterable $names): int
    {
        $governorateId = DB::table('governorates')->where('code', $governorateCode)->value('id');

        if (! $governorateId) {
            return 0;
        }

        $global = DB::table('cities')->where('governorate_id', $governorateId)->whereNull('company_id')
            ->orderBy('id')->get(['id', 'name_ar']);
        $merged = 0;

        foreach ($names as $name) {
            $from = $global->first(fn ($city) => $city->name_ar === $name);
            $to = $from ? $global->first(fn ($city) => $city->id < $from->id
                && Arabic::looseFold($city->name_ar) === Arabic::looseFold($name)) : null;

            if ($to) {
                self::merge($from->id, $to->id);
                $merged++;
            }
        }

        return $merged;
    }

    /** كل ما أشار إلى منطقةٍ يشير إلى أخرى، وتُحذف الأولى */
    private static function merge(int $from, int $to): void
    {
        DB::transaction(function () use ($from, $to) {
            // مندوبٌ أو إعدادٌ على المنطقتين معاً (شركةٌ أضافت الاسم بكتابتين): يبقى ما على
            // الباقية. القيم أوّلاً: MySQL لا يحذف من جدولٍ بشرطٍ يقرأ الجدول نفسه
            $couriers = DB::table('courier_zones')->where('city_id', $to)->pluck('courier_id')->all();
            $companies = DB::table('city_settings')->where('city_id', $to)->pluck('company_id')->all();
            DB::table('courier_zones')->where('city_id', $from)->whereIn('courier_id', $couriers)->delete();
            DB::table('city_settings')->where('city_id', $from)->whereIn('company_id', $companies)->delete();

            foreach (self::REFERENCES as [$table, $column]) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }

            DB::table('cities')->where('id', $from)->delete();
        });
    }
}
