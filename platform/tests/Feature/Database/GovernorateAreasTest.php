<?php

namespace Tests\Feature\Database;

use App\Models\City;
use App\Models\Governorate;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مناطق المحافظات غير بغداد: لكلٍّ منها أحياء مركزها وأقضيتها ونواحيها، تصل
 * الخادم القائم مع التحديث والتثبيت الجديد مع البذر، ولا تتكرّر منطقة.
 */
class GovernorateAreasTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_01_02_002900_add_governorate_areas.php';

    /** @return array<string, list<string>> */
    private function areas(): array
    {
        return require database_path('data/governorate-areas.php');
    }

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    private function duplicates(int $governorateId): array
    {
        return City::where('governorate_id', $governorateId)->pluck('name_ar')
            ->map(fn (string $name) => Arabic::fold($name))
            ->countBy()
            ->filter(fn (int $n) => $n > 1)
            ->all();
    }

    public function test_every_governorate_but_baghdad_has_its_list_of_clean_distinct_names(): void
    {
        $this->seedReference();

        $codes = Governorate::where('code', '!=', 'BGD')->pluck('code')->sort()->values()->all();
        $this->assertSame($codes, collect($this->areas())->keys()->sort()->values()->all());

        foreach ($this->areas() as $code => $names) {
            $this->assertNotEmpty($names, $code);
            $this->assertCount(count($names), array_unique(array_map(Arabic::fold(...), $names)), "منطقتان بالاسم المطويّ نفسه في {$code}");

            foreach ($names as $name) {
                $this->assertSame(trim($name), $name);
                $this->assertNotSame('', $name);
                $this->assertLessThanOrEqual(120, mb_strlen($name), "«{$name}» أطول من عمود name_ar");
            }
        }
    }

    public function test_seeding_gives_each_governorate_its_areas_once(): void
    {
        $this->seedReference();

        foreach ($this->areas() as $code => $names) {
            $governorate = Governorate::where('code', $code)->firstOrFail();
            $folded = City::where('governorate_id', $governorate->id)->pluck('name_ar')->map(fn ($n) => Arabic::fold($n))->flip();

            $this->assertSame([], $this->duplicates($governorate->id), "منطقة مكرّرة في {$governorate->name_ar}");

            foreach ($names as $name) {
                $this->assertTrue($folded->has(Arabic::fold($name)), "«{$name}» ليست في {$governorate->name_ar}");
            }
        }

        // البصرة: مناطقها الأولى وأحياء مدينتها معاً — «العشار» و«الجزائر» و«خمسة ميل»
        $basra = Governorate::where('code', 'BSR')->value('id');
        foreach (['العشار', 'الجزائر', 'خمسة ميل', 'الزبير'] as $name) {
            $this->assertTrue(City::where('governorate_id', $basra)->where('name_ar', $name)->exists(), $name);
        }

        $total = City::count();
        $this->seed(\Database\Seeders\GovernorateSeeder::class);
        $this->assertSame($total, City::count(), 'إعادة البذر أضافت مناطق');
    }

    /**
     * خادمٌ قائم: محافظاته ومناطقه الأولى من البذر القديم وحده. الترحيل يضيف ما
     * ينقص، ويبقى ما كان بمعرّفه (عليه شحناتٌ ومناطق مندوبين)، ومرّةً ثانية لا يضيف.
     */
    public function test_migration_adds_the_areas_on_an_existing_server_without_touching_what_is_there(): void
    {
        $this->seedReference();

        $mosul = Governorate::where('code', 'NIN')->value('id');
        $listed = collect($this->areas())->flatten()->map(fn ($n) => Arabic::fold($n))->flip();

        // حال الخادم قبل التحديث: ما في القوائم الجديدة لم يكن
        City::whereIn('governorate_id', Governorate::where('code', '!=', 'BGD')->select('id'))->get()
            ->filter(fn (City $city) => $listed->has(Arabic::fold($city->name_ar)))
            ->each->delete();

        $before = City::where('governorate_id', $mosul)->pluck('id', 'name_ar');
        $this->assertTrue($before->has('الموصل'));
        $this->assertFalse($before->has('حي الزهور'));

        $this->runMigration();

        $this->assertSame($before->get('الموصل'), City::where('governorate_id', $mosul)->where('name_ar', 'الموصل')->value('id'));
        $this->assertTrue(City::where('governorate_id', $mosul)->where('name_ar', 'حي الزهور')->exists());

        foreach (Governorate::where('code', '!=', 'BGD')->pluck('id') as $id) {
            $this->assertSame([], $this->duplicates($id), 'منطقة مكرّرة بعد الترحيل');
        }

        $total = City::count();
        $this->runMigration();
        $this->assertSame($total, City::count(), 'الترحيل مرّةً ثانية أضاف مناطق');
    }

    public function test_migration_on_a_fresh_install_leaves_the_areas_to_the_seeder(): void
    {
        DB::table('cities')->delete();
        DB::table('governorates')->delete();

        $this->runMigration();

        $this->assertSame(0, City::count());
    }
}
