<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentStages;
use App\Support\StreamingXlsx;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * «كل مراحل النقل» وفلاتر القائمة وتصديرها — كما في المعتاد: العدّاد يفتح
 * قائمته، والقائمة تُصدَّر كما تُرى.
 */
class StagesAndExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** شحنةٌ ثم حالها كما يُطلب — الحال هنا مادّة الاستعلام لا موضوع الاختبار. */
    private function shipment(array $state = [], array $input = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state, $input) {
            $shipment = app(CreateShipment::class)->handle($input + [
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $shipment->forceFill($state + ['status_changed_at' => now()])->save();

            return $shipment->refresh();
        });
    }

    private function listTotal(array $query): int
    {
        $html = $this->actingAs($this->owner)->get($this->host().'/shipments?'.http_build_query($query))
            ->assertOk()->getContent();

        preg_match('/إجمالي النتائج:\s*([\d,]+)/u', $html, $m);

        return (int) str_replace(',', '', $m[1] ?? '-1');
    }

    // ── كل مراحل النقل ─────────────────────────────────────────────

    public function test_every_stage_counter_matches_the_list_it_opens(): void
    {
        $this->shipment();                                                     // جاهزة للطبع
        $this->shipment(['status' => 'pending_pickup']);
        $this->shipment(['status' => 'at_hub']);
        $this->shipment(['status' => 'out_for_delivery']);
        $this->shipment(['status' => 'failed_attempt', 'status_changed_at' => now()->subDays(5)]);
        $this->shipment(['status' => 'returning']);                            // عند المندوب
        $this->shipment(['status' => 'returning', 'return_received_at' => now()]); // على الرفّ
        $this->shipment(['status' => 'delivered', 'collected_amount' => 50_000]);
        $this->shipment(['status' => 'delivered', 'collected_amount' => 40_000]); // تغيّر المبلغ
        $this->shipment(['status' => 'delivered', 'collected_amount' => 50_000, 'type' => 'exchange']);
        $this->shipment(['status' => 'delivered', 'collected_amount' => 50_000, 'merchant_settled_at' => now()]); // خرجت
        $this->shipment(['status' => 'in_transit']);

        $expected = [
            'ready_to_print' => 1, 'ready_for_pickup' => 1, 'incoming' => 0, 'in_store' => 1,
            'out_for_delivery' => 1, 'to_process' => 1, 'postponed' => 0, 'return_with_courier' => 1,
            'return_on_shelf' => 1, 'delivered' => 1, 'partial_or_exchange' => 1, 'amount_changed' => 1,
            'in_transit' => 1, 'returns_to_sort' => 0, 'returns_on_the_way' => 0,
        ];

        $page = $this->actingAs($this->owner)->get($this->host().'/shipments/stages')->assertOk();
        $page->assertSee('كل مراحل النقل')->assertSee('أقدمها منذ 5 أيام');

        foreach ($expected as $stage => $count) {
            $this->assertNotNull(ShipmentStages::find($stage), $stage);
            $this->assertSame($count, $this->listTotal(['stage' => $stage]), "المرحلة {$stage}");
        }

        // كل مرحلةٍ في اللوحة لها توقّعٌ هنا: مرحلةٌ جديدة بلا اختبار تُكتشف
        $all = collect(ShipmentStages::groups())->flatMap(fn ($g) => array_keys($g['stages']))->all();
        $this->assertEqualsCanonicalizing(array_keys($expected), $all);
    }

    public function test_the_board_counts_only_what_the_user_may_see(): void
    {
        $there = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']));

        $this->shipment(['status' => 'at_hub']);
        $this->shipment(['status' => 'at_hub', 'branch_id' => $there->id]);

        // موظّف فرع البصرة: الفرع الرئيسي وحده يرى الفروع كلّها
        $clerk = $this->makeUser($this->company, UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $there->id])->save());

        $html = $this->actingAs($clerk)->get($this->host().'/shipments?stage=in_store')->assertOk()->getContent();
        $this->assertStringContainsString('إجمالي النتائج: 1', $html);
    }

    /**
     * المرحلة تُفتح في مكانها: اللوحة تبقى أعلى الصفحة والمختارة مضيئة، وتحتها
     * قسمها — شحناتها وحدها (الأقدم في المرحلة أوّلاً) وبحثها وشاشاتها.
     */
    public function test_a_stage_opens_its_list_in_place_under_the_board(): void
    {
        $old = $this->shipment(['status' => 'out_for_delivery', 'status_changed_at' => now()->subDays(4)]);
        $new = $this->shipment(['status' => 'out_for_delivery']);
        $elsewhere = $this->shipment(['status' => 'at_hub']);

        $page = $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=out_for_delivery')->assertOk();
        $html = $page->getContent();

        // اللوحة باقية بروابطها إلى الصفحة نفسها، والمختارة مضيئة
        $page->assertSee('بالمخزن')
            ->assertSee('href="'.route('shipments.stages', ['stage' => 'in_store']).'"', false)
            ->assertSee('aria-current="page"', false);

        // القسم: شحنات المرحلة وحدها، الأقدم فيها أوّلاً، ومنذ متى فيها، وشاشاتها
        $this->assertStringContainsString($old->number, $html);
        $this->assertStringContainsString($new->number, $html);
        $this->assertStringNotContainsString($elsewhere->number, $html);
        $this->assertLessThan(strpos($html, $new->number), strpos($html, $old->number), 'الأقدم في المرحلة أوّلاً');
        $page->assertSee('في المرحلة منذ')->assertSee('4 أيام')
            ->assertSee('إجمالي النتائج: 2')
            ->assertSee('href="'.route('courier-manifests.index').'"', false)
            ->assertSee('data-bulk-bar', false);

        // البحث يبقى في المرحلة
        $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=out_for_delivery&q='.$new->number)
            ->assertOk()->assertSee('إجمالي النتائج: 1')->assertSee('name="stage" value="out_for_delivery"', false);

        // مرحلةٌ لا تُعرف: اللوحة وحدها
        $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=nothing')
            ->assertOk()->assertDontSee('إجمالي النتائج');
    }

    public function test_a_stage_list_shows_only_what_the_user_may_see_and_open(): void
    {
        $there = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']));

        $mine = $this->shipment(['status' => 'at_hub', 'branch_id' => $there->id]);
        $theirs = $this->shipment(['status' => 'at_hub']);

        $clerk = $this->makeUser($this->company, UserRole::CustomerService);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $there->id])->save());

        $page = $this->actingAs($clerk)->get($this->host().'/shipments/stages?stage=in_store')->assertOk();

        $page->assertSee($mine->number)->assertDontSee($theirs->number)->assertSee('إجمالي النتائج: 1');
        // خدمة العملاء لا تُدير النقل: «كشوف المناديب» لا تظهر رابطاً يُفضي إلى 403
        $page->assertDontSee('href="'.route('courier-manifests.index').'"', false);
    }

    // ── الفلاتر ─────────────────────────────────────────────────────

    public function test_the_wide_filters_narrow_the_list(): void
    {
        [$karrada, $mansour] = Tenancy::runFor($this->company, fn () => [
            City::where('governorate_id', $this->baghdad()->id)->where('name_ar', 'الكرادة')->value('id'),
            City::where('governorate_id', $this->baghdad()->id)->where('name_ar', 'المنصور')->value('id'),
        ]);

        $this->shipment(['merchant_settled_at' => now()], ['city_id' => $karrada, 'cod_amount' => 25_000]);
        $this->shipment(['status_changed_at' => now()->subDays(10)], ['city_id' => $mansour, 'cod_amount' => 75_000]);
        $this->shipment(['type' => 'exchange'], ['city_id' => $mansour]);

        $this->assertSame(1, $this->listTotal(['city_id' => $karrada]));
        $this->assertSame(1, $this->listTotal(['settled' => 'yes']));
        $this->assertSame(2, $this->listTotal(['settled' => 'no']));
        $this->assertSame(1, $this->listTotal(['type' => 'exchange']));
        // المبلغ كما يُكتب، بأرقامٍ عربية وفاصل
        $this->assertSame(1, $this->listTotal(['amount' => '٢٥٬٠٠٠']));
        $this->assertSame(1, $this->listTotal(['stage_to' => now()->subDays(5)->toDateString()]));
        $this->assertSame(2, $this->listTotal(['stage_from' => now()->subDay()->toDateString()]));
        // تاريخٌ لا يُفهم لا يُسقط الصفحة ولا يقصر شيئاً
        $this->assertSame(3, $this->listTotal(['stage_from' => 'أمس']));
    }

    public function test_current_branch_follows_the_hub_not_the_origin(): void
    {
        [$basra, $hub] = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);

            return [$basra, Hub::create(['code' => 'H2', 'name' => 'مركز البصرة', 'type' => 'main',
                'branch_id' => $basra->id, 'is_active' => true])];
        });

        $this->shipment(['status' => 'at_hub', 'hub_id' => $hub->id]);
        $this->shipment(['status' => 'at_hub']);

        $this->assertSame(1, $this->listTotal(['current_branch_id' => $basra->id]));
        $this->assertSame(0, $this->listTotal(['branch_id' => $basra->id]));
    }

    // ── التصدير ─────────────────────────────────────────────────────

    public function test_excel_export_is_the_filtered_list_with_full_phones_and_is_audited(): void
    {
        $this->shipment(['status' => 'at_hub'], ['recipient_phone' => '07701112233', 'cod_amount' => 125_000]);
        $this->shipment(['status' => 'delivered', 'collected_amount' => 50_000]);

        $response = $this->actingAs($this->owner)->get($this->host().'/shipments/export?stage=in_store')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('رقم الوصل', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame(2, $sheet->getHighestDataRow(), 'صفّ العناوين وشحنةٌ واحدة');
        // الهاتف نصٌّ بصفره، والمبلغ رقم
        $this->assertSame('07701112233', (string) $sheet->getCell('H2')->getValue());
        $this->assertSame(125000, (int) $sheet->getCell('N2')->getValue());
        $this->assertSame('بالمخزن', ShipmentStages::find('in_store')['label']);

        Tenancy::runFor($this->company, function () {
            $log = AuditLog::where('action', 'shipments_exported')->sole();
            // MySQL يعيد ترتيب مفاتيح JSON: المقارنة بالأزواج لا بالترتيب
            $this->assertEquals(['format' => 'excel', 'rows' => 1, 'matching' => 1, 'filters' => ['stage' => 'in_store']], $log->new_values);
            $this->assertSame($this->owner->id, $log->user_id);
        });
    }

    public function test_the_print_page_lists_the_same_and_is_audited(): void
    {
        $this->shipment([], ['recipient_phone' => '07701112233']);

        $this->actingAs($this->owner)->get($this->host().'/shipments/export/print')
            ->assertOk()->assertSee('07701112233')->assertSee('قائمة الشحنات');

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            'print', AuditLog::where('action', 'shipments_exported')->sole()->new_values['format'],
        ));
    }

    public function test_export_needs_its_own_ability(): void
    {
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($agent)->get($this->host().'/shipments/export')->assertForbidden();
        $this->actingAs($agent)->get($this->host().'/shipments/export/print')->assertForbidden();
        $this->actingAs($agent)->get($this->host().'/shipments')->assertOk()->assertDontSee('/shipments/export');

        // ولمن يملكها زرّان فوق القائمة بفلاترها
        $this->actingAs($this->owner)->get($this->host().'/shipments?status=at_hub')
            ->assertSee('/shipments/export?status=at_hub', false);
    }

    public function test_the_sheet_writer_names_columns_and_drops_what_xml_refuses(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA'],
            array_map(StreamingXlsx::column(...), [0, 25, 26, 51, 52, 701, 702]));

        $writer = new StreamingXlsx([['نصّ', 10], ['رقم', 10, 'number']], 'ورقة: [تجربة]');
        $writer->add(["سطر\x07فيه جرس", '1500']);
        $writer->add(['<b>&</b>', null]);

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-').'.xlsx';
        $writer->save($path);

        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();

        $this->assertSame('ورقة   تجربة', $sheet->getTitle());
        $this->assertSame('سطرفيه جرس', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame(1500, (int) $sheet->getCell('B2')->getValue());
        $this->assertSame('<b>&</b>', (string) $sheet->getCell('A3')->getValue());
        $this->assertSame(2, $writer->count());

        @unlink($path);
    }
}
