<?php

namespace Tests\Feature\Reports;

use App\Actions\Pickups\PayPickupCommission;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\PickupPayout;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما في المعتاد من تقارير لم تكن عندنا، ومعالجة التاجر محاولاته الفاشلة من
 * بوابته، وتأكيد استلام الدفعات.
 */
class ReferenceReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private User $merchantUser;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->merchantUser] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 1000]),
            User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $this->merchant->id, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $path = [], string $source = 'web', ?User $by = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($path, $source, $by) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
                'source' => $source,
            ], $by ?? $this->owner);

            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    private function failed(): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt]);
    }

    // ------------------------------------------------ معالجة التاجر

    public function test_a_permitted_merchant_decides_on_his_failed_attempts_from_the_portal(): void
    {
        $failed = $this->failed();

        // بلا الإذن: لا شاشة ولا قرار
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/processing')->assertForbidden();
        $this->actingAs($this->merchantUser)->get($this->host().'/portal')->assertDontSee('للمعالجة');

        Tenancy::runFor($this->company, fn () => $this->merchant->update(['can_process' => true]));

        $this->actingAs($this->merchantUser)->get($this->host().'/portal/processing')->assertOk()->assertSee($failed->number);

        $this->actingAs($this->merchantUser)->post($this->host()."/portal/processing/{$failed->id}", ['action' => 'postpone'])
            ->assertSessionHasErrors('until');

        $this->actingAs($this->merchantUser)->post($this->host()."/portal/processing/{$failed->id}", [
            'action' => 'redeliver', 'note' => 'يستلم بعد العصر',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::OutForDelivery, $failed->fresh()->status);

        Tenancy::runFor($this->company, function () use ($failed) {
            $event = ShipmentEvent::where('shipment_id', $failed->id)->where('event_type', 'processed')->sole();
            $this->assertSame(['merchant', 'merchant', 'redeliver'], [$event->actor_type, $event->meta['by'], $event->meta['action']]);
            $this->assertStringContainsString('يستلم بعد العصر', $event->note);
        });

        // وقد عولجت: لا قرار ثانٍ، لا منه ولا من الموظّف
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/processing/{$failed->id}", ['action' => 'return'])
            ->assertSessionHasErrors('action');
        $this->actingAs($this->owner)->post($this->host()."/processing/{$failed->id}", ['action' => 'return'])
            ->assertSessionHasErrors('action');

        // وشحنة تاجرٍ آخر لا تُعالَج من هنا
        $other = Tenancy::runFor($this->company, fn () => $this->makeMerchant($this->company, 'M0002'));
        $foreign = Tenancy::runFor($this->company, function () use ($other) {
            $s = app(CreateShipment::class)->handle(['merchant_id' => $other->id, 'recipient_name' => 'زيد', 'recipient_phone' => '07801234568',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 1000], $this->owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt] as $st) {
                app(ChangeShipmentStatus::class)->handle($s->refresh(), $st, $this->owner, ['courier_id' => $st === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $s;
        });
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/processing/{$foreign->id}", ['action' => 'return'])->assertNotFound();

        // وتقرير المتابعة يفرّق بين التاجر والموظّف
        $staffCase = $this->failed();
        $this->actingAs($this->owner)->post($this->host()."/processing/{$staffCase->id}", ['action' => 'return']);

        $bySource = $this->actingAs($this->owner)->get($this->host().'/reports/processing')->assertOk()->viewData('bySource');
        $this->assertSame(['merchant' => 1, 'staff' => 1], collect($bySource)->sortKeys()->all());
    }

    // ------------------------------------------------ تأكيد الدفعات

    public function test_paid_amounts_wait_for_their_owners_confirmation(): void
    {
        [$settlement, $payout, $agent] = Tenancy::runFor($this->company, function () {
            $settlement = MerchantSettlement::create(['merchant_id' => $this->merchant->id, 'code' => 'MS1', 'status' => 'paid',
                'net_amount' => 90_000, 'paid_at' => now(), 'shipments_count' => 1]);

            $agent = Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام', 'phone' => '07730000001', 'type' => 'pickup', 'status' => 'active']);
            app(Ledger::class)->recordPickupShare(\App\Models\PickupShare::create([
                'courier_id' => $agent->id, 'shipments_count' => 3, 'rate' => 500, 'amount' => 1500, 'status' => 'accrued',
            ]), $agent, $this->owner);
            app(PayPickupCommission::class)->handle($agent->refresh(), $this->owner);

            return [$settlement, PickupPayout::sole(), $agent];
        });

        $report = fn () => $this->actingAs($this->owner)->get($this->host().'/reports/unconfirmed')->assertOk();
        $report()->assertSee($this->merchant->business_name)->assertSee('90,000')->assertSee('حيدر الاستلام');

        $this->actingAs($this->merchantUser)->get($this->host().'/portal/statement')->assertSee('استلمتُها');
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/settlements/{$settlement->id}/confirm")->assertSessionHasNoErrors();
        $this->actingAs($this->merchantUser)->post($this->host()."/portal/settlements/{$settlement->id}/confirm")->assertSessionHasErrors('settlement');

        $agentUser = Tenancy::runFor($this->company, fn () => User::create(['name' => 'حيدر', 'phone' => '07730000001', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $agent->id, 'is_active' => true]));
        $this->actingAs($agentUser)->get($this->host().'/courier/shares')->assertSee($payout->number)->assertSee('استلمتُها');
        $this->actingAs($agentUser)->post($this->host()."/courier/payouts/{$payout->id}/confirm")->assertSessionHasNoErrors();

        $report()->assertSee('كل ما دُفع للتجّار أكّدوه.')->assertSee('كل دفعات الأرباح أكّدها أصحابها.');

        // ولا يؤكّد أحدٌ عن غيره
        $stranger = Tenancy::runFor($this->company, fn () => User::create(['name' => 'غريب', 'phone' => '07790000002', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->makeMerchant($this->company, 'M0003')->id, 'is_active' => true]));
        $this->actingAs($stranger)->post($this->host()."/portal/settlements/{$settlement->id}/confirm")->assertNotFound();
    }

    // ------------------------------------------------ التقارير

    public function test_entries_by_person_channel_and_baghdad_hour(): void
    {
        $staff = $this->shipment();
        $portal = $this->shipment([], 'merchant_portal', $this->merchantUser);

        // السادسة صباحاً بتوقيت الخادم هي التاسعة ببغداد
        Tenancy::runFor($this->company, fn () => Shipment::whereKey([$staff->id, $portal->id])
            ->update(['created_at' => today()->setTime(6, 0)]));

        $response = $this->actingAs($this->owner)->get($this->host().'/reports/entries')->assertOk()
            ->assertSee('إدخال موظّف')->assertSee('بوابة التاجر');

        $this->assertSame([9 => 2], $response->viewData('byHour')->all());
        $this->assertSame(2, $response->viewData('total'));
    }

    public function test_portal_uploads_by_day_and_how_many_were_picked_up(): void
    {
        $this->shipment([ShipmentStatus::PickedUp], 'merchant_portal', $this->merchantUser);
        $this->shipment([], 'merchant_portal', $this->merchantUser);
        $this->shipment(); // من موظّف: لا يُعَدّ هنا

        $days = $this->actingAs($this->owner)->get($this->host().'/reports/portal')->assertOk()->viewData('days');

        $this->assertCount(1, $days);
        $this->assertSame([2, 1], [(int) $days[0]->created, (int) $days[0]->picked]);
    }

    public function test_stuck_shipments_older_than_the_chosen_hours(): void
    {
        $old = $this->shipment([ShipmentStatus::PickedUp]);
        $fresh = $this->shipment([ShipmentStatus::PickedUp]);
        Tenancy::runFor($this->company, fn () => $old->forceFill(['status_changed_at' => now()->subHours(50)])->save());

        $listed = fn (int $hours) => $this->actingAs($this->owner)->get($this->host().'/reports/stuck?hours='.$hours)
            ->assertOk()->viewData('shipments')->getCollection()->pluck('id')->all();

        $this->assertSame([$old->id], $listed(48));
        $this->assertSame([], $listed(72));
        $this->assertNotContains($fresh->id, $listed(1));
        $this->actingAs($this->owner)->get($this->host().'/reports/stuck?hours=48')->assertSee('أكثر من 48 ساعة');
    }

    public function test_couriers_paid_above_the_fee_and_profit_by_merchant(): void
    {
        $normal = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
        $over = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
        Tenancy::runFor($this->company, fn () => $over->forceFill(['courier_commission' => 9000])->save());

        $listed = $this->actingAs($this->owner)->get($this->host().'/reports/courier-overcharge')->assertOk()
            ->viewData('shipments')->getCollection()->pluck('id')->all();
        $this->assertSame([$over->id], $listed);

        $rows = collect($this->actingAs($this->owner)->get($this->host().'/reports/merchant-profit')->assertOk()->viewData('rows')->items());
        $row = $rows->firstWhere('merchant_id', $this->merchant->id);
        $fees = (int) $normal->total_fees + (int) $over->total_fees;
        $this->assertSame([2, $fees, 1000 + 9000], [(int) $row->delivered, (int) $row->revenue, (int) $row->commission]);

        // والمالية لمن يرى أرباح الشركة وحده
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host().'/reports/merchant-profit')->assertForbidden();
    }

    public function test_merchants_on_special_price_lists(): void
    {
        Tenancy::runFor($this->company, function () {
            $list = PriceList::create(['name' => 'تسعيرة الجملة', 'is_active' => true]);
            PriceListRule::create(['price_list_id' => $list->id, 'to_governorate_id' => $this->baghdad()->id, 'weight_from_grams' => 0,
                'weight_to_grams' => 5000, 'delivery_fee' => 3500, 'peripheral_fee' => 4500, 'priority' => 1, 'is_active' => true]);
            $this->makeMerchant($this->company, 'M0009')->update(['price_list_id' => $list->id, 'business_name' => 'متجر الجملة']);
        });

        $this->actingAs($this->owner)->get($this->host().'/reports/special-prices')->assertOk()
            ->assertSee('متجر الجملة')->assertSee('تسعيرة الجملة')->assertSee('3,500')->assertSee('4,500')
            ->assertDontSee($this->merchant->business_name);
    }
}
