<?php

namespace Tests\Feature\Database;

use App\Models\City;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Support\Arabic;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مناطق بغداد الـ٣٥٥ تصل الخادم القائم مع التحديث، والتثبيت الجديد مع البذر،
 * ولا تتكرّر منطقة في الحالين — ولو كُتبت بهمزة أو تاء مربوطة أخرى.
 */
class BaghdadAreasTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_01_02_001300_add_baghdad_areas.php';

    /** @return list<string> */
    private function areas(): array
    {
        return require database_path('data/baghdad-areas.php');
    }

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    /** أسماء بغداد مطويّةً، بعدد تكرار كلٍّ منها. */
    private function foldedCounts(int $baghdad): array
    {
        return City::where('governorate_id', $baghdad)->pluck('name_ar')
            ->map(fn (string $name) => Arabic::fold($name))
            ->countBy()
            ->all();
    }

    public function test_the_list_holds_355_distinct_areas_without_placeholders(): void
    {
        $areas = $this->areas();

        $this->assertCount(355, $areas);
        $this->assertCount(355, array_unique(array_map(Arabic::fold(...), $areas)), 'منطقتان بالاسم المطويّ نفسه');
        $this->assertNotContains('غير محدد', $areas);

        foreach ($areas as $area) {
            $this->assertSame(trim($area), $area);
            $this->assertLessThanOrEqual(120, mb_strlen($area), "«{$area}» أطول من عمود name_ar");
        }
    }

    public function test_seeding_gives_baghdad_every_area_once(): void
    {
        $this->seedReference();
        $baghdad = $this->baghdad()->id;

        $counts = $this->foldedCounts($baghdad);

        $this->assertSame([], array_filter($counts, fn (int $n) => $n > 1), 'منطقة مكرّرة في بغداد');

        foreach ($this->areas() as $area) {
            $this->assertArrayHasKey(Arabic::fold($area), $counts, "«{$area}» ليست في بغداد");
        }

        // ما كان يُكتب بالهمزة يبقى بها: لا يُستبدل بصيغة القائمة
        $this->assertTrue(City::where('governorate_id', $baghdad)->where('name_ar', 'الأعظمية')->exists());
        $this->assertFalse(City::where('governorate_id', $baghdad)->where('name_ar', 'الاعظمية')->exists());
        $this->assertTrue(City::where('governorate_id', $baghdad)->where('name_ar', 'الراشدية')->exists());
        $this->assertFalse(City::where('governorate_id', $baghdad)->where('name_ar', 'الرشيدية')->exists());

        $total = City::count();
        $this->seed(\Database\Seeders\GovernorateSeeder::class);
        $this->assertSame($total, City::count(), 'إعادة البذر أضافت مناطق');
    }

    /**
     * خادمٌ قائم: محافظاته ومناطقه الأولى من البذر القديم، وعليها شحنات
     * ومناطق مندوبين. الترحيل يضيف ما ينقص ويصحّح «الرشيدية» بمعرّفها.
     */
    public function test_migration_adds_areas_on_an_existing_server_and_keeps_what_points_at_them(): void
    {
        $this->seedReference();
        $baghdad = $this->baghdad()->id;

        // حال الخادم قبل التحديث: الأربع والأربعون الأولى، و«الرشيدية» بخطئها
        $original = ['الكرادة', 'الأعظمية', 'أبو غريب', 'الرشيدية', 'الكرخ', 'حي العامل'];
        City::where('governorate_id', $baghdad)->whereNotIn('name_ar', $original)->where('name_ar', '!=', 'الراشدية')->delete();
        City::where('governorate_id', $baghdad)->where('name_ar', 'الراشدية')->update(['name_ar' => 'الرشيدية']);
        $this->assertSame(count($original), City::where('governorate_id', $baghdad)->count());

        $rashidiya = City::where('governorate_id', $baghdad)->where('name_ar', 'الرشيدية')->value('id');
        $company = $this->makeCompany();
        $zone = Tenancy::runFor($company, function () use ($baghdad, $rashidiya) {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07701234567', 'type' => 'delivery']);

            return CourierZone::create(['courier_id' => $courier->id, 'governorate_id' => $baghdad, 'city_id' => $rashidiya]);
        });

        $this->runMigration();

        $this->assertSame('الراشدية', City::find($rashidiya)->name_ar);
        $this->assertSame($rashidiya, Tenancy::runFor($company, fn () => $zone->fresh()->city_id));

        $counts = $this->foldedCounts($baghdad);
        $this->assertSame([], array_filter($counts, fn (int $n) => $n > 1), 'منطقة مكرّرة بعد الترحيل');

        // الأربع والخمسون الأولى وما في القائمة: «الكرخ» ليست فيها، والباقي منها
        $this->assertSame(355 + 1, City::where('governorate_id', $baghdad)->count());
        $this->assertTrue(City::where('governorate_id', $baghdad)->where('name_ar', 'الأعظمية')->exists());

        // مرّةً ثانية لا تضيف شيئاً
        $this->runMigration();
        $this->assertSame(356, City::where('governorate_id', $baghdad)->count());
    }

    public function test_migration_on_a_fresh_install_leaves_the_areas_to_the_seeder(): void
    {
        DB::table('cities')->delete();
        DB::table('governorates')->delete();

        $this->runMigration();

        $this->assertSame(0, City::count());
    }
}
