<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة الشحنة (docs/plan/53): «إعادة توصيل» و«واصل إجباري» في قائمة الحالة، و«راسل المندوب
 * عنها»، و«حركات الطلب» بمن عدّل وماذا غيّر، وأجور التاجر للمحاسب ولمن يملك صلاحيتها.
 */
class ShipmentPageToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $state = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
            ], $this->owner);

            $shipment->forceFill($state)->save();

            return $shipment;
        });
    }

    public function test_redeliver_is_offered_after_an_attempt_and_goes_back_out(): void
    {
        $failed = $this->shipment(['status' => 'failed_attempt', 'delivery_courier_id' => $this->courier->id, 'attempts_count' => 1]);
        $fresh = $this->shipment(['status' => 'at_hub']);

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$failed->id)->assertOk()
            ->assertSee('<option value="redeliver"', false);
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$fresh->id)->assertOk()
            ->assertDontSee('<option value="redeliver"', false);

        // المتعثّرة: معالجةٌ باسمه، وتخرج مع مندوبها «إعادة توصيل»
        $this->actingAs($this->owner)->post($this->host()."/shipments/{$failed->id}/status", ['status' => 'redeliver', 'note' => 'الزبون رد'])
            ->assertSessionHasNoErrors();
        Tenancy::runFor($this->company, function () use ($failed) {
            $failed->refresh();
            $this->assertSame(ShipmentStatus::OutForDelivery, $failed->status);
            $this->assertNotNull($failed->redelivery_at);
            $this->assertTrue($failed->events()->where('event_type', 'processed')->exists());
        });

        // بالمخزن بعد محاولة: يُختار المندوب
        $back = $this->shipment(['status' => 'at_hub', 'attempts_count' => 1]);
        $this->actingAs($this->owner)->post($this->host()."/shipments/{$back->id}/status", ['status' => 'redeliver'])
            ->assertSessionHasErrors('courier_id');
        $this->actingAs($this->owner)->post($this->host()."/shipments/{$back->id}/status", ['status' => 'redeliver', 'courier_id' => $this->courier->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::OutForDelivery, Tenancy::runFor($this->company, fn () => $back->refresh()->status));

        // ولا تُعاد شحنةٌ لم تُحاوَل
        $this->actingAs($this->owner)->post($this->host()."/shipments/{$fresh->id}/status", ['status' => 'redeliver', 'courier_id' => $this->courier->id])
            ->assertSessionHasErrors('status');
    }

    public function test_forced_delivered_is_in_the_list_for_whoever_may_force_it(): void
    {
        $shipment = $this->shipment(['status' => 'at_hub']);
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk()
            ->assertSee('<option value="forced_delivered"', false);
        $this->actingAs($agent)->get($this->host().'/shipments/'.$shipment->id)->assertOk()
            ->assertDontSee('<option value="forced_delivered"', false);

        // بلا صلاحيته لا يمرّ ولو أُرسل بيد
        $this->actingAs($agent)->post($this->host()."/shipments/{$shipment->id}/status", [
            'status' => 'forced_delivered', 'force_delivered_reason' => 'x', 'collected_amount' => 50_000,
        ])->assertSessionHasErrors('status');

        // وبلا سبب لا يمرّ
        $this->actingAs($this->owner)->post($this->host()."/shipments/{$shipment->id}/status", [
            'status' => 'forced_delivered', 'collected_amount' => 50_000,
        ])->assertSessionHasErrors('force_delivered_reason');

        $this->actingAs($this->owner)->post($this->host()."/shipments/{$shipment->id}/status", [
            'status' => 'forced_delivered', 'force_delivered_reason' => 'الزبون أكّد الاستلام', 'collected_amount' => 50_000,
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $shipment->refresh();
            $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
            $this->assertTrue((bool) $shipment->is_forced);
            $this->assertSame('الزبون أكّد الاستلام', $shipment->forced_reason);
            $this->assertSame(50_000, $shipment->collected_amount);
        });
    }

    public function test_message_the_courier_replaces_message_an_employee(): void
    {
        $this->setFeature($this->company, \App\Enums\Feature::Conversations);
        $shipment = $this->shipment(['status' => 'at_hub']);

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk()
            ->assertSee('راسل المندوب عنها')->assertDontSee('راسل موظّفاً عنها')
            ->assertSee('حركات الطلب');
    }

    public function test_order_activity_names_who_changed_what(): void
    {
        $shipment = $this->shipment(['status' => 'at_hub']);
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        Tenancy::runFor($this->company, fn () => $agent->update(['name' => 'زينب الكول سنتر']));

        $this->actingAs($agent->refresh())->put($this->host().'/shipments/'.$shipment->id, [
            'recipient_name' => 'علي', 'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'city_id' => $this->area(), 'pieces_count' => 1, 'cod_amount' => 45_000,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id.'/activity')->assertOk()
            ->assertSee('زينب الكول سنتر')
            ->assertSeeInOrder(['المبلغ المطلوب:', '50,000', '45,000'])
            ->assertSee('1 تعديل');
    }

    public function test_merchant_fees_are_for_the_accountant_and_whoever_holds_the_permission(): void
    {
        $shipment = $this->shipment(['status' => 'at_hub', 'extra_fee' => 2000]);
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->assertTrue($accountant->can('shipments.override'));
        $this->assertFalse($agent->can('shipments.override'));

        $this->actingAs($agent)->get($this->host().'/shipments/'.$shipment->id.'/edit')->assertOk()
            ->assertDontSee('name="delivery_fee"', false)->assertSee('تعديلها للمحاسب');

        // ما يُرسَل بيدٍ لا يمرّ: الأجرة من التسعيرة، والرسوم كما كانت
        $this->actingAs($agent)->put($this->host().'/shipments/'.$shipment->id, [
            'recipient_name' => 'علي', 'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'city_id' => $this->area(), 'pieces_count' => 1, 'cod_amount' => 50_000,
            'delivery_fee' => 0, 'extra_fee' => 0, 'discount' => 4000,
        ])->assertSessionHasNoErrors();
        Tenancy::runFor($this->company, function () use ($shipment) {
            $shipment->refresh();
            $this->assertSame([5000, 2000, 0], [$shipment->delivery_fee, $shipment->extra_fee, $shipment->discount]);
        });

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id.'/edit')->assertOk()
            ->assertSee('name="delivery_fee"', false);
    }
}
