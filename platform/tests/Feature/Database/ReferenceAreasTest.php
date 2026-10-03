<?php

namespace Tests\Feature\Database;

use App\Actions\Shipments\CreateShipment;
use App\Models\City;
use App\Models\CitySetting;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use App\Support\Arabic;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\GovernorateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مناطق المحافظات من قائمة النظام الذي تعمل عليه الشركة (database/data/reference-areas.php):
 * نظيفةٌ ولا تكرّر ما كان — ولو كُتب بلا «ال» — وتصل الخادم القائم مع التحديث والتثبيت
 * الجديد مع البذر، ومنطقةٌ أضافتها شركةٌ لنفسها بالاسم نفسه تُضمّ إليها.
 */
class ReferenceAreasTest extends TestCase
{
    use RefreshDatabase;

    /** ترحيلات القوائم بترتيبها: الخادم القائم يجريها كلّها مع التحديث */
    private const MIGRATIONS = [
        '2026_01_03_000100_add_reference_areas.php',
        '2026_01_03_000200_merge_duplicate_reference_areas.php',
        '2026_01_03_000300_add_karbala_reference_areas.php',
    ];

    /** @return array<string, list<string>> */
    private function lists(): array
    {
        return require database_path('data/reference-areas.php');
    }

    private function runMigrations(): void
    {
        foreach (self::MIGRATIONS as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    /** ما في القائمة من مناطق المحافظة يُحذف — كما كان الخادم قبل التحديث */
    private function forgetListed(string $code): int
    {
        $governorateId = DB::table('governorates')->where('code', $code)->value('id');

        return DB::table('cities')->where('governorate_id', $governorateId)->whereNull('company_id')
            ->whereIn('name_ar', $this->lists()[$code])->delete();
    }

    public function test_the_lists_are_clean(): void
    {
        $lists = $this->lists();
        $this->assertCount(505, $lists['BGD']);
        $this->assertCount(223, $lists['KRB']);

        foreach ($lists as $code => $names) {
            $this->assertSame(count($names), count(array_unique(array_map(Arabic::looseFold(...), $names))), "منطقتان بالاسم نفسه في {$code}");

            foreach ($names as $name) {
                $this->assertSame(trim(preg_replace('/\s+/u', ' ', $name)), $name, "«{$name}»: مسافات زائدة");
                $this->assertLessThanOrEqual(120, mb_strlen($name), "«{$name}» أطول من عمود name_ar");
                $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $name, "«{$name}»: أرقام عربية");
                $this->assertDoesNotMatchRegularExpression('/ة[\x{0621}-\x{064A}]/u', $name, "«{$name}»: كلمتان ملتصقتان");
                $this->assertDoesNotMatchRegularExpression('/[\x{0621}-\x{064A}][0-9]|[0-9][\x{0621}-\x{064A}]/u', $name, "«{$name}»: رقمٌ ملتصق");
                $this->assertDoesNotMatchRegularExpression('/(^|\s)(\S+)\s+\2(\s|$)/u', $name, "«{$name}»: كلمةٌ مكرّرة");
            }
        }

        $this->assertNotContains('pd', $lists['BGD']);
        $this->assertContains('مدينة الصدر - قطاع 70', $lists['BGD']);
        $this->assertContains('الحرية دباش', $lists['BGD']);
        // موجودةٌ قبلُ بـ«حي» أو بدونها: «حي الرشاد»
        $this->assertNotContains('الرشاد', $lists['BGD']);

        $this->assertContains('الانتفاضة 1', $lists['KRB']);
        $this->assertContains('طريق النجف من عمود (655-1425)', $lists['KRB']);
        // «مركز» في قائمة كربلاء هي «مركز كربلاء» الموجودة، و«النقيب» هي «حي النقيب»
        $this->assertNotContains('مركز', $lists['KRB']);
        $this->assertNotContains('النقيب', $lists['KRB']);
    }

    public function test_seeding_gives_each_listed_area_once_beside_what_was_there(): void
    {
        $this->seedReference();
        $loose = [];

        foreach ($this->lists() as $code => $names) {
            $loose[$code] = City::where('governorate_id', Governorate::where('code', $code)->value('id'))
                ->pluck('name_ar')->map(Arabic::looseFold(...))->countBy();

            foreach ($names as $name) {
                $this->assertSame(1, $loose[$code][Arabic::looseFold($name)] ?? 0, "«{$name}» ليست في {$code} مرّةً واحدة");
            }
        }

        // «صالحية» في القائمة الأصلية هي «الصالحية» الموجودة، و«النقيب» في قائمة كربلاء هي
        // «حي النقيب»: لم تُضف أيٌّ منهما بجانب صاحبتها
        $this->assertSame(1, $loose['BGD'][Arabic::looseFold('صالحية')]);
        $this->assertFalse(City::where('governorate_id', $this->baghdad()->id)->where('name_ar', 'صالحية')->exists());
        $this->assertSame(1, $loose['KRB'][Arabic::looseFold('النقيب')]);
        $this->assertTrue(City::where('name_ar', 'حي النقيب')->exists());
        $this->assertFalse(City::where('name_ar', 'النقيب')->exists());

        $total = City::count();
        $this->seed(GovernorateSeeder::class);
        $this->assertSame($total, City::count(), 'إعادة البذر أضافت مناطق');
    }

    public function test_migration_adds_the_areas_on_an_existing_server_once(): void
    {
        $this->seedReference();
        $full = City::count();

        foreach ($this->lists() as $code => $names) {
            $this->assertSame(count($names), $this->forgetListed($code));
        }
        $this->assertSame($full - 505 - 223, City::count());

        $this->runMigrations();
        $this->assertSame($full, City::count());
        $this->assertTrue(City::where('governorate_id', $this->baghdad()->id)->whereNull('company_id')->where('name_ar', 'مدينة الصدر - قطاع 33')->exists());
        $this->assertTrue(City::whereNull('company_id')->where('name_ar', 'الانتفاضة 1')->exists());

        $this->runMigrations();
        $this->assertSame($full, City::count(), 'الترحيل الثاني أضاف');
    }

    /**
     * خادمٌ حدّث بقائمة بغداد الأولى، وفيها «الرشاد» وعنده «حي الرشاد»، وعليها شحنةٌ وتاجر
     * ومندوبٌ وأجرة: تُضمّ إلى «حي الرشاد» — وما كان على الاثنتين يبقى مرّةً واحدة.
     */
    public function test_an_area_the_first_list_added_twice_is_merged_into_the_older_one(): void
    {
        $this->seedReference();
        $baghdad = $this->baghdad()->id;
        $older = City::where('governorate_id', $baghdad)->whereNull('company_id')->where('name_ar', 'حي الرشاد')->value('id');
        $this->assertNotNull($older);

        $twice = DB::table('cities')->insertGetId(['governorate_id' => $baghdad, 'name_ar' => 'الرشاد', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($company);

        [$shipment, $zone, $setting] = Tenancy::runFor($company, function () use ($baghdad, $merchant, $twice, $older) {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07701234567', 'type' => 'delivery']);
            $merchant->forceFill(['city_id' => $twice])->save();
            CourierZone::create(['courier_id' => $courier->id, 'governorate_id' => $baghdad, 'city_id' => $older]);

            return [
                app(CreateShipment::class)->handle(['merchant_id' => $merchant->id, 'recipient_phone' => '07801234567',
                    'governorate_id' => $baghdad, 'city_id' => $twice, 'cod_amount' => 25_000]),
                // المندوب نفسه على الاثنتين: يبقى صفٌّ واحد
                CourierZone::create(['courier_id' => $courier->id, 'governorate_id' => $baghdad, 'city_id' => $twice]),
                CitySetting::create(['city_id' => $twice, 'delivery_fee' => 6000]),
            ];
        });

        $this->runMigrations();

        $this->assertFalse(DB::table('cities')->where('id', $twice)->exists(), 'بقيت «الرشاد» بجانب «حي الرشاد»');
        $this->assertSame($older, DB::table('shipments')->where('id', $shipment->id)->value('city_id'));
        $this->assertSame($older, DB::table('merchants')->where('id', $merchant->id)->value('city_id'));
        $this->assertSame($older, DB::table('city_settings')->where('id', $setting->id)->value('city_id'));
        $this->assertFalse(DB::table('courier_zones')->where('id', $zone->id)->exists());
        $this->assertSame(1, DB::table('courier_zones')->where('city_id', $older)->count());

        // ولا يمسّ ما ليس مكرّراً: مرّةً ثانية لا شيء
        $total = City::count();
        $this->runMigrations();
        $this->assertSame($total, City::count());
    }

    /**
     * شركةٌ أضافت «الحريه دباش» لنفسها لأنّها نقصت القائمة، وعليها شحنةٌ ومندوبٌ وأجرة:
     * بعد التحديث تراها مرّةً واحدة — العامّة — وكل ما أشار إلى نسختها يشير إليها.
     */
    public function test_a_company_copy_is_folded_into_the_new_area(): void
    {
        $this->seedReference();
        $baghdad = $this->baghdad()->id;
        $this->forgetListed('BGD');

        $company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($company);

        [$copy, $kept, $shipment, $zone, $setting] = Tenancy::runFor($company, function () use ($baghdad, $merchant) {
            $copy = City::create(['governorate_id' => $baghdad, 'company_id' => Tenancy::id(), 'name_ar' => 'الحريه دباش', 'is_active' => true]);
            // ونسخةٌ ثانية أحدث بالتعريف، عليها المندوب نفسه وإعدادٌ آخر: يبقى ما على الأقدم
            $second = City::create(['governorate_id' => $baghdad, 'company_id' => Tenancy::id(), 'name_ar' => 'الحرية الدباش', 'is_active' => true]);
            $kept = City::create(['governorate_id' => $baghdad, 'company_id' => Tenancy::id(), 'name_ar' => 'حارة لا تعرفها القائمة', 'is_active' => true]);
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07701234567', 'type' => 'delivery']);
            $merchant->forceFill(['city_id' => $copy->id])->save();
            CourierZone::create(['courier_id' => $courier->id, 'governorate_id' => $baghdad, 'city_id' => $second->id]);
            CitySetting::create(['city_id' => $second->id, 'delivery_fee' => 7000]);

            return [
                $copy, $kept,
                app(CreateShipment::class)->handle(['merchant_id' => $merchant->id, 'recipient_phone' => '07801234567',
                    'governorate_id' => $baghdad, 'city_id' => $copy->id, 'cod_amount' => 25_000]),
                CourierZone::create(['courier_id' => $courier->id, 'governorate_id' => $baghdad, 'city_id' => $copy->id]),
                CitySetting::create(['city_id' => $copy->id, 'delivery_fee' => 6000, 'is_peripheral' => true]),
            ];
        });

        $this->runMigrations();

        $global = City::where('governorate_id', $baghdad)->whereNull('company_id')->where('name_ar', 'الحرية دباش')->value('id');
        $this->assertNotNull($global);
        $this->assertFalse(DB::table('cities')->where('id', $copy->id)->exists(), 'بقيت نسخة الشركة');
        $this->assertTrue(DB::table('cities')->where('id', $kept->id)->exists(), 'حُذفت منطقةٌ ليست في القائمة');

        $this->assertSame($global, DB::table('shipments')->where('id', $shipment->id)->value('city_id'));
        $this->assertSame($global, DB::table('merchants')->where('id', $merchant->id)->value('city_id'));
        $this->assertSame($global, DB::table('courier_zones')->where('id', $zone->id)->value('city_id'));
        $this->assertSame($global, DB::table('city_settings')->where('id', $setting->id)->value('city_id'));
        $this->assertSame(1, DB::table('courier_zones')->where('city_id', $global)->count());
        $this->assertSame(1, DB::table('city_settings')->where('city_id', $global)->count());
        $this->assertSame(0, DB::table('cities')->whereNotNull('company_id')->where('name_ar', 'like', '%دباش%')->count());

        // والشركة ترى الاسم مرّةً واحدة
        $this->assertSame(1, Tenancy::runFor($company, fn () => City::where('governorate_id', $baghdad)->get()
            ->filter(fn (City $city) => Arabic::looseFold($city->name_ar) === Arabic::looseFold('الحرية دباش'))->count()));
    }

    public function test_migration_on_a_fresh_install_leaves_the_areas_to_the_seeder(): void
    {
        DB::table('cities')->delete();
        DB::table('governorates')->delete();

        $this->runMigrations();

        $this->assertSame(0, City::count());
    }
}
