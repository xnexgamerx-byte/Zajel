<?php

namespace Tests\Feature\Shipments;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Import\ShipmentSheet;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * «منطقة ناقصة»: المنطقة إلزامية في الشحنة، فما نقص من القائمة تضيفه الشركة
 * لنفسها — تراه هي وحدها، في الشحنة والإدخال السريع والملفّات.
 */
class CompanyAreasTest extends TestCase
{
    use RefreshDatabase;

    private Company $zajel;

    private Company $barq;

    private User $owner;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->zajel = $this->makeCompany('zajel', 'الزاجل');
        $this->barq = $this->makeCompany('barq', 'البرق');
        $this->owner = $this->makeUser($this->zajel);
        $this->merchant = $this->makeMerchant($this->zajel);
    }

    private function host(Company $company): string
    {
        return 'http://'.$company->slug.'.'.config('zajel.tenant_domain');
    }

    private function basra(): Governorate
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
    }

    private function add(string $name, ?Governorate $governorate = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post($this->host($this->zajel).'/areas/add', [
            'governorate_id' => ($governorate ?? $this->basra())->id, 'name_ar' => $name,
        ]);
    }

    private function ownArea(string $name): City
    {
        return Tenancy::runFor($this->zajel, fn () => City::where('name_ar', $name)->firstOrFail());
    }

    /** أسماء مناطق المحافظة في نموذج «شحنة جديدة» كما يراها $as — من بيانات القائمة نفسها */
    private function areasOffered(Company $company, User $as, Governorate $governorate): array
    {
        $html = $this->actingAs($as)->get($this->host($company).'/shipments/create')->assertOk()->getContent();
        preg_match('#<script type="application/json" id="cities-data">(.*?)</script>#s', $html, $match);

        return array_column(json_decode($match[1], true)[$governorate->id] ?? [], 'name');
    }

    private function shipTo(City $city, Company $company, User $as)
    {
        $merchant = $company->is($this->zajel) ? $this->merchant : $this->makeMerchant($company, 'M0009');

        return $this->actingAs($as)->post($this->host($company).'/shipments', [
            'merchant_id' => $merchant->id, 'recipient_phone' => '07801234567',
            'governorate_id' => $city->governorate_id, 'city_id' => $city->id, 'cod_amount' => 25000,
            'pieces_count' => 1, 'fees_paid_by' => 'merchant', 'type' => 'delivery',
        ]);
    }

    public function test_a_company_adds_a_missing_area_and_ships_to_it(): void
    {
        $this->add('  حي   الأمير الجديد ')
            ->assertSessionHas('success', 'أُضيفت «حي الأمير الجديد» إلى مناطق البصرة: تظهر الآن في الشحنات والإدخال السريع والملفّات.');

        $area = $this->ownArea('حي الأمير الجديد');
        $this->assertSame($this->zajel->id, $area->company_id);

        // في قائمة مناطق الشحنة، وتُحفظ إليها شحنة
        $this->assertContains('حي الأمير الجديد', $this->areasOffered($this->zajel, $this->owner, $this->basra()));
        $this->shipTo($area, $this->zajel, $this->owner)->assertSessionHasNoErrors();
        $this->assertSame($area->id, Tenancy::runFor($this->zajel, fn () => Shipment::latest('id')->value('city_id')));

        // وعلى صفحة المناطق موسومةٌ بأنها من الشركة
        $this->actingAs($this->owner)->get($this->host($this->zajel).'/areas?governorate_id='.$this->basra()->id.'&q=الأمير')
            ->assertOk()->assertSee('أضافتها الشركة');
    }

    public function test_another_company_neither_sees_nor_uses_it(): void
    {
        $this->add('حي الأمير الجديد');
        $area = $this->ownArea('حي الأمير الجديد');
        $theirs = $this->makeUser($this->barq);

        $offered = $this->areasOffered($this->barq, $theirs, $this->basra());
        $this->assertNotEmpty($offered);
        $this->assertNotContains('حي الأمير الجديد', $offered);

        // برقمها في الطلب: لا تُقبل
        $this->shipTo($area, $this->barq, $theirs)->assertSessionHasErrors('city_id');

        // ولا تُحذف من هناك
        $this->actingAs($theirs)->delete($this->host($this->barq).'/areas/'.$area->id)->assertNotFound();
        $this->assertTrue(Tenancy::runFor($this->zajel, fn () => City::whereKey($area->id)->exists()));
    }

    public function test_an_excel_row_finds_the_company_area_by_name(): void
    {
        $this->add('حي الأمير الجديد');

        $read = function (Company $company) {
            $path = tempnam(sys_get_temp_dir(), 'zajel-test-').'.xlsx';
            $book = new Spreadsheet;
            $book->getActiveSheet()->fromArray([
                ['هاتف المستلم', 'المحافظة', 'المنطقة', 'المبلغ'],
                ['07801234567', 'البصرة', 'حي الامير الجديد', 25000],
            ], null, 'A1');
            (new Xlsx($book))->save($path);

            return Tenancy::runFor($company, fn () => app(ShipmentSheet::class)->read($path))->first();
        };

        $ours = $read($this->zajel);
        $this->assertSame([], $ours['errors']);
        $this->assertSame($this->ownArea('حي الأمير الجديد')->id, $ours['data']['city_id']);

        $this->assertStringContainsString('ليست في البصرة', implode(' ', $read($this->barq)['errors']));
    }

    /** صفّ Excel بـ«صالحية» يجد «الصالحية»، و«الرشاد» «حي الرشاد»، و«مدينه قطاع 33» قطاعها — والملتبس يُردّ */
    public function test_an_excel_row_finds_an_area_written_without_its_article(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zajel-test-').'.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['هاتف المستلم', 'المحافظة', 'المنطقة', 'المبلغ'],
            ['07801234567', 'بغداد', 'صالحية', 25000],
            ['07801234568', 'بغداد', 'مدينه قطاع 33', 25000],
            ['07801234569', 'بغداد', 'شارع 60', 25000],
            ['07801234570', 'بغداد', 'الرشاد', 25000],
        ], null, 'A1');
        (new Xlsx($book))->save($path);

        $rows = Tenancy::runFor($this->zajel, fn () => app(ShipmentSheet::class)->read($path));

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame($this->area('الصالحية'), $rows[0]['data']['city_id']);
        // كلماته كلّها في منطقةٍ واحدة فهي هي
        $this->assertSame([], $rows[1]['errors']);
        $this->assertSame($this->area('مدينة الصدر - قطاع 33'), $rows[1]['data']['city_id']);
        // وما يحتمل مناطق عدّة لا يُخمَّن
        $this->assertStringContainsString('ليست في بغداد', implode(' ', $rows[2]['errors']));
        // و«الرشاد» بلا «حي» هي «حي الرشاد»
        $this->assertSame([], $rows[3]['errors']);
        $this->assertSame($this->area('حي الرشاد'), $rows[3]['data']['city_id']);
    }

    public function test_a_name_already_there_is_not_added_twice_even_spelled_differently(): void
    {
        // «الكراده» هي «الكرادة» العامّة — و«كراده» بلا «ال» كذلك
        $this->add('الكراده', $this->baghdad())
            ->assertSessionHasErrors(['name_ar' => '«الكرادة» موجودة سلفاً في بغداد.']);
        $this->add('كراده', $this->baghdad())
            ->assertSessionHasErrors(['name_ar' => '«الكرادة» موجودة سلفاً في بغداد.']);
        // و«الرشاد» بلا «حي» هي «حي الرشاد»
        $this->add('الرشاد', $this->baghdad())
            ->assertSessionHasErrors(['name_ar' => '«حي الرشاد» موجودة سلفاً في بغداد.']);

        $this->add('حي الأمير الجديد')->assertSessionHas('success');
        $this->add('حى الامير الجديد')->assertSessionHasErrors(['name_ar' => '«حي الأمير الجديد» موجودة سلفاً في البصرة.']);

        $this->assertSame(1, Tenancy::runFor($this->zajel, fn () => City::whereNotNull('company_id')->count()));
    }

    public function test_an_unused_area_is_deleted_and_a_used_one_only_hidden(): void
    {
        $this->add('حي الأمير الجديد');
        $this->add('حي خطأ إملائي');
        $used = $this->ownArea('حي الأمير الجديد');
        $unused = $this->ownArea('حي خطأ إملائي');
        $this->shipTo($used, $this->zajel, $this->owner)->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->delete($this->host($this->zajel).'/areas/'.$unused->id)
            ->assertSessionHas('success', 'حُذفت «حي خطأ إملائي».');
        $this->assertFalse(Tenancy::runFor($this->zajel, fn () => City::whereKey($unused->id)->exists()));

        // تحملها شحنة: تُخفى من القوائم وتبقى في الشحنة
        $this->actingAs($this->owner)->delete($this->host($this->zajel).'/areas/'.$used->id)
            ->assertSessionHas('success', 'أُخفيت «حي الأمير الجديد» من القوائم، وبقيت فيما يحملها من شحناتٍ وسجلّات.');
        $this->assertFalse(Tenancy::runFor($this->zajel, fn () => City::find($used->id)->is_active));
        $this->assertNotContains('حي الأمير الجديد', $this->areasOffered($this->zajel, $this->owner, $this->basra()));
        $this->assertSame('حي الأمير الجديد', Tenancy::runFor($this->zajel, fn () => Shipment::latest('id')->first()->city->name_ar));

        // وإضافتها ثانيةً تعيدها هي، لا نسخةً عنها
        $this->add('حي الأمير الجديد')->assertSessionHas('success');
        $this->assertTrue(Tenancy::runFor($this->zajel, fn () => City::find($used->id)->is_active));

        // والعامّة لا تُحذف
        $this->actingAs($this->owner)->delete($this->host($this->zajel).'/areas/'.$this->area())->assertNotFound();
    }

    public function test_adding_an_area_needs_the_pricing_settings(): void
    {
        $clerk = $this->makeUser($this->zajel, UserRole::Operations);

        $this->add('حي الأمير الجديد', null, $clerk)->assertForbidden();
    }
}
