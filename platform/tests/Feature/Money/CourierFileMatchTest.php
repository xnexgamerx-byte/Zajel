<?php

namespace Tests\Feature\Money;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Settlements\CourierFileMatch;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * «مطابقة الوصولات» في كشف المندوب (docs/plan/124): ملف ما سلّمه يُطابَق بالكشف وصلاً وصلاً، فيُحاسَب على
 * المطابق وحده، ويُضاف للكشف ما بالملف وليس به.
 */
class CourierFileMatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private Courier $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->other] = Tenancy::runFor($this->company, fn () => [
            Courier::create([
                'code' => 'C1', 'name' => 'الوسيط', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 4000, 'commission_per_return' => 0,
            ]),
            Courier::create([
                'code' => 'C2', 'name' => 'مندوبٌ آخر', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 3000, 'commission_per_return' => 0,
            ]),
        ]);

        Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod, ?Courier $courier = null, ?string $order = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $courier, $order) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id,
                'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد',
                'landmark' => 'قرب الجامع',
                'cod_amount' => $cod,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner,
                ['courier_id' => ($courier ?? $this->courier)->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->owner);

            if ($order) {
                $shipment->forceFill(['aggregator_order_id' => $order])->save();
            }

            return $shipment->refresh();
        });
    }

    /** ملف كما يصدّره الوسيط: رقم طلبه، ورقم وصلنا ببادئته، والمبلغ والأجرة بفواصل، والحالة */
    private function file(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['رقم الطلب', 'رقم طلب الشركة', 'اسم الزبون', 'سعر الطلب', 'سعر التوصيل', 'حالة الطلب'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'match').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'طلبات الوسيط.xlsx', null, null, true);
    }

    private function draft(): CourierSettlement
    {
        return Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->owner));
    }

    public function test_the_file_is_matched_to_the_statement_and_the_courier_is_settled_on_the_matched_ones(): void
    {
        $this->setFeature($this->company, Feature::CourierFileMatch);

        $ok = $this->deliver(20_000, order: '3317883');
        $byNumber = $this->deliver(24_000);
        $amount = $this->deliver(30_000);
        $absent = $this->deliver(15_000);
        $draft = $this->draft();
        $later = $this->deliver(18_000);          // واصلةٌ بعد بناء الكشف: ليست به
        $elsewhere = $this->deliver(10_000, $this->other);

        $file = $this->file([
            ['3317883', 'ZAJ_999999', 'الزبون', '20,000', '4,000', 'تم التسليم للزبون'],   // برقم طلب الوسيط
            ['', 'ZAJ_'.$byNumber->number, 'الزبون', '٢٤٬٠٠٠', '4000', 'تم التسليم للزبون'],   // برقم الوصل ببادئته
            ['', $amount->number, 'الزبون', '25,000', '4,000', 'تم التسليم للزبون'],
            ['', $later->number, 'الزبون', '18,000', '4,000', 'تم التسليم للزبون'],
            ['', $elsewhere->number, 'الزبون', '10,000', '4,000', 'تم التسليم للزبون'],
            ['', 'ZAJ_777777', 'الزبون', '9,000', '4,000', 'تم التسليم للزبون'],
            ['', 'ZAJ_'.$byNumber->number, 'الزبون', '24,000', '4,000', 'تم التسليم للزبون'],
        ]);

        $upload = $this->actingAs($this->owner)
            ->post($this->host()."/settlements/couriers/{$draft->id}/match", ['file' => $file]);
        $upload->assertRedirect();
        $page = $upload->headers->get('Location');

        $this->actingAs($this->owner)->get($page)->assertOk()
            ->assertSee('مطابقة الوصولات')
            ->assertSee('فرق بالمبلغ')
            ->assertSee('واصلة وليست بالكشف')
            ->assertSee('عند مندوبٍ آخر')
            ->assertSee('غير موجودة عندنا')
            ->assertSee('مكرّرة بالملف')
            ->assertSee('بالكشف وليست بالملف')
            ->assertSee('777777');

        $result = Tenancy::runFor($this->company, fn () => app(CourierFileMatch::class)
            ->compare($draft->fresh(), app(CourierFileMatch::class)->read($file->getRealPath())));
        $by = fn (string $cat) => $result['items']->where('category', $cat)->map(fn ($i) => $i['shipment']?->id)->values()->all();

        $this->assertSame([$ok->id, $byNumber->id], $by('ok'));
        $this->assertSame([$amount->id], $by('amount'));
        $this->assertSame([$later->id], $by('addable'));
        $this->assertSame([$elsewhere->id], $by('other_courier'));
        $this->assertSame([null], $by('unknown'));
        $this->assertSame([$byNumber->id], $by('duplicate'));
        $this->assertSame([$absent->id], $by('absent'));
        // يُستلم من المطابق: المحصَّل ناقص عمولته
        $this->assertSame(44_000, $result['summary']['ok_amount']);
        $this->assertSame(8_000, $result['summary']['ok_fee']);

        // يُحاسَب على المطابق وحده، وتبقى البقية في المسودّة
        $this->actingAs($this->owner)->from($page)
            ->post($this->host()."/settlements/couriers/{$draft->id}/confirm", ['shipment_ids' => $result['summary']['ok_ids']])
            ->assertRedirect($page);

        Tenancy::runFor($this->company, function () use ($draft, $ok, $byNumber, $amount) {
            $this->assertSame(2, (int) $draft->fresh()->shipments_count);
            $this->assertNotSame($draft->id, $ok->fresh()->courier_settlement_id);
            $this->assertSame($ok->fresh()->courier_settlement_id, $byNumber->fresh()->courier_settlement_id);
            $this->assertSame('confirmed', CourierSettlement::find($ok->fresh()->courier_settlement_id)->status);
            // ما فيه فرقٌ يبقى في المسودّة
            $this->assertTrue($draft->lines()->where('shipment_id', $amount->id)->exists());
        });

        // وما بالملف وليس بالكشف يُضاف، فيُطابَق من جديد
        $this->actingAs($this->owner)->from($page)
            ->post($this->host()."/settlements/couriers/{$draft->id}/add", ['shipment_ids' => [$later->id]])
            ->assertRedirect($page);

        $this->actingAs($this->owner)->get($page)->assertOk()->assertSee('محاسَبة سابقاً');
        Tenancy::runFor($this->company, fn () => $this->assertTrue($draft->lines()->where('shipment_id', $later->id)->exists()));
    }

    public function test_a_status_that_is_not_delivered_and_a_fee_difference_are_flagged(): void
    {
        $this->setFeature($this->company, Feature::CourierFileMatch);

        $returned = $this->deliver(20_000);
        $fee = $this->deliver(20_000);
        $draft = $this->draft();

        $rows = Tenancy::runFor($this->company, fn () => app(CourierFileMatch::class)->read($this->file([
            ['', $returned->number, 'الزبون', '20,000', '4,000', 'راجع للتاجر'],
            ['', $fee->number, 'الزبون', '20,000', '5,000', 'تم التسليم للزبون'],
        ])->getRealPath()));
        $result = Tenancy::runFor($this->company, fn () => app(CourierFileMatch::class)->compare($draft->fresh(), $rows));

        $this->assertSame(['status', 'fee'], $result['items']->pluck('category')->all());
        $this->assertSame([], $result['summary']['ok_ids']);
    }

    public function test_a_file_without_a_waybill_column_is_refused_with_a_reason(): void
    {
        $this->setFeature($this->company, Feature::CourierFileMatch);
        $this->deliver(20_000);
        $draft = $this->draft();

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['الاسم', 'المبلغ'], ['علي', '20000']]);
        $path = tempnam(sys_get_temp_dir(), 'match').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($this->owner)->from($this->host()."/settlements/couriers/{$draft->id}")
            ->post($this->host()."/settlements/couriers/{$draft->id}/match", ['file' => new UploadedFile($path, 'x.xlsx', null, null, true)])
            ->assertSessionHasErrors(['file']);
    }

    public function test_it_is_closed_until_the_platform_opens_it_for_the_company(): void
    {
        $this->deliver(20_000);
        $draft = $this->draft();

        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$draft->id}")
            ->assertOk()->assertDontSee('مطابقة الوصولات');

        $this->actingAs($this->owner)
            ->post($this->host()."/settlements/couriers/{$draft->id}/match", ['file' => $this->file([['1', '2', 'x', '1', '1', 'x']])])
            ->assertForbidden();

        $this->setFeature($this->company, Feature::CourierFileMatch);
        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$draft->id}")
            ->assertOk()->assertSee('مطابقة الوصولات');
    }
}
