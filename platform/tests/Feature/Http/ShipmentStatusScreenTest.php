<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentStatusScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->user = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $statuses = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($statuses) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => 50_000,
            ], $this->user);

            $change = app(ChangeShipmentStatus::class);

            foreach ($statuses as $status) {
                $change->handle($shipment->refresh(), $status, $this->user, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    public function test_the_detail_screen_offers_only_the_legal_next_statuses(): void
    {
        $shipment = $this->shipment();

        $html = $this->actingAs($this->user)
            ->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('الإجراء التالي')
            ->assertSee('بانتظار الاستلام')
            ->assertSee('تم الاستلام')
            ->getContent();

        // القائمة العادية وحدها: لوحة الإجبار تعرض ما خرج عن المسار عمداً،
        // وهي خلف تفصيلة مطويّة وسبب إلزامي
        $normal = substr($html, 0, strpos($html, 'forced_status'));

        $this->assertStringNotContainsString('value="delivered"', $normal);
    }

    public function test_the_forced_panel_offers_exactly_what_the_normal_one_refuses(): void
    {
        $shipment = $this->shipment();

        $this->actingAs($this->user)
            ->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('تغيير إجباري خارج المسار')
            ->assertSee('يُسجَّل باسمك وسببه')
            ->assertSee('forced_reason', escape: false);
    }

    public function test_an_illegal_transition_is_refused_with_a_field_error(): void
    {
        $shipment = $this->shipment();

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        $this->assertSame(ShipmentStatus::Created, $shipment->fresh()->status);
    }

    public function test_dispatching_without_a_courier_is_refused(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'out_for_delivery'])
            ->assertSessionHasErrors('courier_id');
    }

    public function test_a_failed_attempt_requires_a_categorised_reason(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'failed_attempt'])
            ->assertSessionHasErrors('failure_reason_id');
    }

    public function test_a_reason_that_requires_a_note_refuses_an_empty_one(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery]);
        $reason = FailureReason::where('code', 'address_not_found')->firstOrFail();

        $this->assertTrue($reason->requires_note);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status'            => 'failed_attempt',
                'failure_reason_id' => $reason->id,
            ])
            ->assertSessionHasErrors('note');
    }

    public function test_a_failed_attempt_is_recorded_with_its_reason(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery]);
        $reason = FailureReason::where('code', 'no_answer')->firstOrFail();

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status'            => 'failed_attempt',
                'failure_reason_id' => $reason->id,
                'note'              => 'اتصلت ثلاث مرات',
            ])
            ->assertSessionHas('success');

        $shipment->refresh();

        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->status);
        $this->assertSame(1, $shipment->attempts_count);
        $this->assertSame($reason->id, $shipment->last_failure_reason_id);
    }

    public function test_collecting_more_than_owed_is_refused(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status'           => 'delivered',
                'collected_amount' => 90_000,
            ])
            ->assertSessionHasErrors('collected_amount');
    }

    public function test_a_partial_delivery_without_an_amount_is_refused(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'partially_delivered'])
            ->assertSessionHasErrors('collected_amount');
    }

    public function test_a_courier_of_another_company_cannot_be_assigned(): void
    {
        $other = $this->makeCompany('barq', 'البرق');

        $foreignCourier = Tenancy::runFor($other, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب البرق', 'phone' => '07720000099',
            'type' => 'delivery', 'status' => 'active',
        ]));

        $shipment = $this->shipment([ShipmentStatus::PickedUp]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/'.$shipment->id.'/status', [
                'status'     => 'out_for_delivery',
                'courier_id' => $foreignCourier->id,
            ])
            ->assertSessionHasErrors('courier_id');
    }

    public function test_bulk_assign_dispatches_the_eligible_shipments_and_skips_the_rest(): void
    {
        $ready = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);
        $alsoReady = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);
        $cancelled = $this->shipment([ShipmentStatus::Cancelled]);   // لا تخرج — ويُقال لماذا

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/assign', [
                'shipment_ids' => [$ready->id, $alsoReady->id, $cancelled->id],
                'courier_id'   => $this->courier->id,
            ])
            ->assertSessionHas('success', 'أُسندت شحنتان إلى أحمد الساعدي. تُخطّيت شحنة واحدة: '.$cancelled->number.' (ملغاة).');

        $this->assertSame(ShipmentStatus::OutForDelivery, $ready->fresh()->status);
        $this->assertSame(ShipmentStatus::OutForDelivery, $alsoReady->fresh()->status);
        $this->assertSame(ShipmentStatus::Cancelled, $cancelled->fresh()->status);

        $this->assertSame($this->courier->id, $ready->fresh()->delivery_courier_id);
    }

    /**
     * ما زالت «تم الإنشاء» أو «بانتظار الاستلام»: من يُسندها بيده الطرد —
     * التاجر أحضره أو المندوب يأخذه من عنده. تُستلم ثم تخرج، والسجلّ يقول ذلك.
     */
    public function test_bulk_assign_receives_a_shipment_still_with_the_merchant_then_sends_it_out(): void
    {
        $new = $this->shipment();
        $waiting = $this->shipment([ShipmentStatus::PendingPickup]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/assign', [
                'shipment_ids' => [$new->id, $waiting->id],
                'courier_id'   => $this->courier->id,
            ])
            ->assertSessionHas('success',
                'أُسندت شحنتان إلى أحمد الساعدي (استُلمت من التاجر أوّلاً: '.$new->number.'، '.$waiting->number.').');

        foreach ([$new, $waiting] as $shipment) {
            $fresh = $shipment->fresh();
            $this->assertSame(ShipmentStatus::OutForDelivery, $fresh->status);
            $this->assertSame($this->courier->id, $fresh->delivery_courier_id);
            $this->assertNotNull($fresh->picked_up_at);

            $steps = Tenancy::runFor($this->company, fn () => \App\Models\ShipmentEvent::where('shipment_id', $shipment->id)
                ->where('event_type', 'status_change')->orderBy('id')->pluck('to_status')->all());
            $this->assertSame(['picked_up', 'out_for_delivery'], array_slice($steps, -2));
        }
    }

    /** لا شيء خرج: رسالةٌ حمراء تقول لماذا، لا «أُسندت 0 شحنة» خضراء */
    public function test_nothing_assigned_is_an_error_that_names_each_reason(): void
    {
        $delivered = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
        $withHim = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery]);

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/assign', [
                'shipment_ids' => [$delivered->id, $withHim->id],
                'courier_id'   => $this->courier->id,
            ])
            ->assertSessionMissing('success')
            ->assertSessionHasErrors(['shipment_ids' => 'لم تُسنَد أيّ شحنة إلى أحمد الساعدي. تُخطّيت شحنتان: '
                .$delivered->number.' (تم التسليم)، '.$withHim->number.' (معه سلفاً).']);
    }

    public function test_a_shipment_out_with_another_courier_names_him(): void
    {
        $out = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery]);
        $other = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active',
        ]));

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/assign', ['shipment_ids' => [$out->id], 'courier_id' => $other->id])
            ->assertSessionHasErrors(['shipment_ids' => 'لم تُسنَد أيّ شحنة إلى علي الكعبي. تُخطّيت شحنة واحدة: '
                .$out->number.' (مع أحمد الساعدي).']);

        $this->assertSame($this->courier->id, $out->fresh()->delivery_courier_id);
    }

    public function test_bulk_assign_cannot_touch_another_companys_shipments(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $otherMerchant = $this->makeMerchant($other, 'M9001');
        $otherUser = $this->makeUser($other);

        $foreign = Tenancy::runFor($other, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $otherMerchant->id,
            'recipient_name'  => 'زبون البرق',
            'recipient_phone' => '07809998887',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب السوق',
        ], $otherUser));

        $this->actingAs($this->user)
            ->post($this->host().'/shipments/assign', [
                'shipment_ids' => [$foreign->id],
                'courier_id'   => $this->courier->id,
            ]);

        $this->assertSame(ShipmentStatus::Created, $foreign->fresh()->status);
    }

    public function test_the_cash_board_shows_cash_commission_and_net(): void
    {
        $shipment = $this->shipment([
            ShipmentStatus::PickedUp,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::Delivered,
        ]);

        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);

        $this->actingAs($this->user)
            ->get($this->host().'/couriers/cash')
            ->assertOk()
            ->assertSee('أحمد الساعدي')
            ->assertSee('50,000')    // نقد بيده
            ->assertSee('1,500')     // عمولته
            ->assertSee('48,500');   // الواجب تسليمه
    }
}
