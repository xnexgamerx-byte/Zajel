<?php

namespace Tests\Feature\Transport;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كشف عهدة المندوب.
 *
 * الورقة التي يوقّع عليها المندوب عند الخروج ويُطابَق بها عند العودة.
 * وخطؤها ليس خطأ عرض: شحنةٌ ساقطة من الكشف عهدةٌ بلا إقرار، ومندوبٌ
 * ساقط من القائمة عهدةٌ بلا صاحب.
 */
class CourierManifestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->staff = $this->makeUser($this->company);
        $this->delivery = $this->makeCourier('C1', 'أحمد الساعدي', 'delivery');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeCourier(string $code, string $name, string $type, string $status = 'active'): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => $code, 'name' => $name, 'phone' => $this->phoneFrom($code, '0772'),
            'type' => $type, 'status' => $status,
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function outForDelivery(?Courier $courier = null, int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد — الكرادة',
                'landmark'        => 'مقابل جامع بُراثا',
                'cod_amount'      => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, [
                'courier_id' => ($courier ?? $this->delivery)->id,
            ]);

            return $shipment->refresh();
        });
    }

    // ── الكشف ───────────────────────────────────────────────────────

    public function test_the_sheet_carries_every_shipment_in_the_couriers_hands(): void
    {
        $first = $this->outForDelivery(cod: 40_000);
        $second = $this->outForDelivery(cod: 60_000);

        $response = $this->actingAs($this->staff)
            ->get($this->host().'/courier-manifests/'.$this->delivery->id)
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $response->viewData('shipments')->pluck('id')->all(),
        );
        $this->assertSame(100_000, $response->viewData('totals')->cod);
        $this->assertSame(2, $response->viewData('totals')->shipments);
    }

    /** ما سُلّم خرج من العهدة: إبقاؤه يجعل المندوب مسؤولاً عمّا أدّاه. */
    public function test_a_delivered_shipment_leaves_the_sheet(): void
    {
        $kept = $this->outForDelivery();
        $done = $this->outForDelivery();

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($done->refresh(), ShipmentStatus::Delivered, $this->staff, ['collected_amount' => 50_000]));

        $this->assertSame([$kept->id], $this->actingAs($this->staff)
            ->get($this->host().'/courier-manifests/'.$this->delivery->id)
            ->viewData('shipments')->pluck('id')->all());
    }

    public function test_a_courier_holding_nothing_is_not_listed(): void
    {
        $idle = $this->makeCourier('C2', 'حيدر الجبوري', 'delivery');
        $this->outForDelivery();

        $couriers = $this->actingAs($this->staff)
            ->get($this->host().'/courier-manifests')
            ->assertOk()
            ->viewData('couriers');

        $this->assertNotContains($idle->id, $couriers->pluck('id'));
        $this->assertContains($this->delivery->id, $couriers->pluck('id'));
    }

    /**
     * العهدة لا تُخفى بحال صاحبها.
     *
     * كانت القائمة مفلترة بـ delivering()، فأسقطت مندوب استلامٍ بيده
     * آلاف الشحنات — عهدةٌ ومالٌ لا يظهران في أي شاشة. والفلترة بالنوع
     * تصلح لاختيار مَن يُسنَد إليه، لا لعرض مَن بيده.
     */
    public function test_a_pickup_courier_holding_stock_is_still_listed(): void
    {
        $pickup = $this->makeCourier('C3', 'علي الدليمي', 'pickup');

        Tenancy::runFor($this->company, fn () => Shipment::whereKey($this->outForDelivery()->id)
            ->update(['delivery_courier_id' => $pickup->id]));

        $this->assertContains($pickup->id, $this->actingAs($this->staff)
            ->get($this->host().'/courier-manifests')
            ->viewData('couriers')->pluck('id'));
    }

    public function test_a_suspended_courier_holding_stock_is_still_listed(): void
    {
        $shipment = $this->outForDelivery();

        Tenancy::runFor($this->company, fn () => $this->delivery->update(['status' => 'suspended']));

        $this->assertContains($this->delivery->id, $this->actingAs($this->staff)
            ->get($this->host().'/courier-manifests')
            ->viewData('couriers')->pluck('id'));
    }

    // ── الإسناد ─────────────────────────────────────────────────────

    /**
     * مندوب الاستلام لا يُسنَد إليه توصيل.
     *
     * القائمة المنسدلة لا تعرضه، لكن «إخفاء الزرّ ليس منعاً» والطلب
     * يُصاغ بيد. ومسار الإسناد الجَماعي كان يفرضها ومسار الشحنة
     * الواحدة يكتفي بالوجود.
     */
    public function test_a_pickup_courier_cannot_be_handed_a_delivery(): void
    {
        $pickup = $this->makeCourier('C3', 'علي الدليمي', 'pickup');
        $shipment = Tenancy::runFor($this->company, function () {
            $s = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب المول', 'cod_amount' => 25_000,
            ], $this->staff);

            app(ChangeShipmentStatus::class)->handle($s->refresh(), ShipmentStatus::PickedUp, $this->staff);

            return $s->refresh();
        });

        $this->actingAs($this->staff)
            ->from($this->host().'/shipments/'.$shipment->id)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status' => ShipmentStatus::OutForDelivery->value, 'courier_id' => $pickup->id,
            ])
            ->assertSessionHasErrors('courier_id');

        $this->assertNull($shipment->fresh()->delivery_courier_id);
    }

    public function test_a_suspended_courier_cannot_be_handed_a_delivery(): void
    {
        $frozen = $this->makeCourier('C4', 'كرار الموسوي', 'delivery', 'suspended');
        $shipment = Tenancy::runFor($this->company, function () {
            $s = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب المول', 'cod_amount' => 25_000,
            ], $this->staff);

            app(ChangeShipmentStatus::class)->handle($s->refresh(), ShipmentStatus::PickedUp, $this->staff);

            return $s->refresh();
        });

        $this->actingAs($this->staff)
            ->from($this->host().'/shipments/'.$shipment->id)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status' => ShipmentStatus::OutForDelivery->value, 'courier_id' => $frozen->id,
            ])
            ->assertSessionHasErrors('courier_id');
    }

    // ── العزل ───────────────────────────────────────────────────────

    public function test_one_companys_couriers_never_appear_in_anothers_sheet(): void
    {
        $this->outForDelivery();

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $this->actingAs($otherStaff)
            ->get('http://barq.'.config('zajel.tenant_domain').'/courier-manifests/'.$this->delivery->id)
            ->assertNotFound();
    }
}
