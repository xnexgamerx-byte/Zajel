<?php

namespace Tests\Feature\Returns;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierReturnReceipt;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Backfill\CourierReturnReceipts;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «إيصالات راجع المندوبين»: كلّ استلامٍ لراجع مندوبٍ بالمخزن إيصالٌ برقم — لكلّ مندوبٍ إيصاله.
 */
class CourierReturnReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $ahmed;

    private Courier $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        [$this->ahmed, $this->ali] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
            Courier::create(['code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active']),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** طردٌ فشل تسليمه وقُرّر إرجاعه — بيد المندوب */
    private function returning(Courier $courier, int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->staff);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt, ShipmentStatus::Returning] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->staff, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    public function test_receiving_returns_gives_each_courier_a_numbered_receipt(): void
    {
        $a1 = $this->returning($this->ahmed, 20_000);
        $a2 = $this->returning($this->ahmed, 30_000);
        $b1 = $this->returning($this->ali, 40_000);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/receive', ['shipment_ids' => [$a1->id, $a2->id, $b1->id]])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'RC000001') && str_contains($message, 'RC000002'));

        Tenancy::runFor($this->company, function () use ($a1, $a2, $b1) {
            $ahmed = CourierReturnReceipt::where('courier_id', $this->ahmed->id)->firstOrFail();
            $ali = CourierReturnReceipt::where('courier_id', $this->ali->id)->firstOrFail();

            $this->assertSame([2, 50_000, $this->staff->id], [$ahmed->shipments_count, (int) $ahmed->cod_total, $ahmed->received_by_user_id]);
            $this->assertSame([1, 40_000], [$ali->shipments_count, (int) $ali->cod_total]);
            $this->assertSame([$ahmed->id, $ahmed->id, $ali->id], [
                $a1->refresh()->courier_return_receipt_id, $a2->refresh()->courier_return_receipt_id, $b1->refresh()->courier_return_receipt_id,
            ]);
        });

        $this->actingAs($this->staff)->get($this->host().'/courier-return-receipts')
            ->assertOk()->assertSee('إيصالات راجع المندوبين')
            ->assertSee('RC000001')->assertSee('RC000002')->assertSee('أحمد الساعدي')->assertSee('علي الكعبي');

        // تصفيةٌ بالمندوب، وبحثٌ برقم وصلٍ في الإيصال
        $this->actingAs($this->staff)->get($this->host().'/courier-return-receipts?courier_id='.$this->ali->id)
            ->assertSee('علي الكعبي')->assertDontSee('أحمد الساعدي</td>', false);
        $this->actingAs($this->staff)->get($this->host().'/courier-return-receipts?q='.$a2->number)
            ->assertSee('أحمد الساعدي')->assertDontSee('علي الكعبي</td>', false);

        $receipt = Tenancy::runFor($this->company, fn () => CourierReturnReceipt::where('courier_id', $this->ahmed->id)->firstOrFail());
        $this->actingAs($this->staff)->get($this->host().'/courier-return-receipts/'.$receipt->id.'/print')
            ->assertOk()->assertSee('إيصال استلام راجع من مندوب')
            ->assertSee($a1->number)->assertSee($a2->number)->assertDontSee($b1->number);
    }

    /** ما استُلم قبل الإيصالات يُجمع في إيصالاته — ولا يُعاد جمعه مرّة ثانية */
    public function test_returns_received_before_receipts_get_their_receipts(): void
    {
        // الإيصال يجمع ما استُلم في الدقيقة نفسها: لا تعبر الدقيقة بين الاستلامين
        $this->freezeTime();
        $a1 = $this->returning($this->ahmed);
        $a2 = $this->returning($this->ahmed);

        Tenancy::runFor($this->company, function () use ($a1, $a2) {
            app(ReceiveReturns::class)->handle([$a1->id, $a2->id], $this->staff);
            // كأنهما استُلما قبل الإيصالات
            Shipment::whereKey([$a1->id, $a2->id])->update(['courier_return_receipt_id' => null]);
            CourierReturnReceipt::query()->delete();
        });

        $this->assertSame(1, CourierReturnReceipts::run());
        $this->assertSame(0, CourierReturnReceipts::run());

        Tenancy::runFor($this->company, function () use ($a1) {
            $receipt = CourierReturnReceipt::firstOrFail();
            $this->assertSame([2, $this->ahmed->id], [$receipt->shipments_count, $receipt->courier_id]);
            $this->assertSame($receipt->id, $a1->refresh()->courier_return_receipt_id);
            $this->assertStringStartsWith('RC', $receipt->number);
        });
    }

    public function test_the_menu_shows_the_screen_under_returns(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/')
            ->assertSee('إيصالات راجع المندوبين')
            ->assertSee(route('courier-return-receipts.index', [], false), false);
    }
}
