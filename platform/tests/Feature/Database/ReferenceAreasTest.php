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

    private const MIGRATION = '2026_01_03_000100_add_reference_areas.php';

    /** @return array<string, list<string>> */
    private function lists(): array
    {
        return require database_path('data/reference-areas.php');
    }

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
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
        $this->assertCount(513, $lists['BGD']);

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
    }

    public function test_seeding_gives_each_listed_area_once_beside_what_was_there(): void
    {
        $this->seedReference();

        foreach ($this->lists() as $code => $names) {
            $loose = City::where('governorate_id', Governorate::where('code', $code)->value('id'))
                ->pluck('name_ar')->map(Arabic::looseFold(...))->countBy();

            foreach ($names as $name) {
                $this->assertSame(1, $loose[Arabic::looseFold($name)] ?? 0, "«{$name}» ليست في {$code} مرّةً واحدة");
            }
        }

        // «صالحية» في القائمة الأصلية هي «الصالحية» الموجودة: لم تُضف بجانبها
        $baghdad = $this->baghdad()->id;
        $this->assertSame(1, $loose[Arabic::looseFold('صالحية')]);
        $this->assertFalse(City::where('governorate_id', $baghdad)->where('name_ar', 'صالحية')->exists());

        $total = City::count();
        $this->seed(GovernorateSeeder::class);
        $this->assertSame($total, City::count(), 'إعادة البذر أضافت مناطق');
    }

    public function test_migration_adds_the_areas_on_an_existing_server_once(): void
    {
        $this->seedReference();
        $baghdad = $this->baghdad()->id;
        $full = City::where('governorate_id', $baghdad)->count();

        $this->assertSame(513, $this->forgetListed('BGD'));
        $this->assertSame($full - 513, City::where('governorate_id', $baghdad)->count());

        $this->runMigration();
        $this->assertSame($full, City::where('governorate_id', $baghdad)->count());
        $this->assertTrue(City::where('governorate_id', $baghdad)->whereNull('company_id')->where('name_ar', 'مدينة الصدر - قطاع 33')->exists());

        $this->runMigration();
        $this->assertSame($full, City::where('governorate_id', $baghdad)->count(), 'الترحيل الثاني أضاف');
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

        $this->runMigration();

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

        $this->runMigration();

        $this->assertSame(0, City::count());
    }
}
