<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * خياران من بطاقة العميل في المعتاد:
 *
 * «كود تسليم الشحنة» — يُعطى الكود للتاجر فالزبون، ولا يسلّم المندوب إلّا به.
 * «وضع شحنات العميل تحت المراجعة» — لا تخرج شحناته مع مندوبٍ حتى تُجاز.
 */
class DeliveryCodeAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private User $courierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->courierUser] = Tenancy::runFor($this->company, function () {
            $courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 1500,
            ]);

            return [$courier, User::create([
                'name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
            ])];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantWith(array $flags): void
    {
        Tenancy::runFor($this->company, fn () => $this->merchant->forceFill($flags)->save());
    }

    private function shipment(bool $pickedUp = true): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($pickedUp) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            if ($pickedUp) {
                app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            }

            return $shipment->refresh();
        });
    }

    private function outForDelivery(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment) {
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::OutForDelivery, $this->owner,
                ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    private function deliver(Shipment $shipment, ?string $code)
    {
        return $this->actingAs($this->courierUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, array_filter([
                'action' => 'delivered', 'collected_amount' => 50_000, 'delivery_code' => $code,
            ], fn ($v) => $v !== null));
    }

    // --------------------------------------------------------- كود التسليم

    public function test_the_merchant_sets_both_options_on_the_customer_card(): void
    {
        $this->actingAs($this->owner)
            ->put($this->host()."/merchants/{$this->merchant->id}", [
                'business_name' => $this->merchant->business_name, 'phone' => $this->merchant->phone,
                'governorate_id' => $this->baghdad()->id, 'settlement_cycle' => 'weekly', 'payout_method' => 'cash',
                'status' => 'active', 'requires_delivery_code' => '1', 'hold_for_review' => '1',
            ])
            ->assertSessionHasNoErrors();

        $merchant = Tenancy::runFor($this->company, fn () => $this->merchant->fresh());
        $this->assertTrue($merchant->requires_delivery_code);
        $this->assertTrue($merchant->hold_for_review);
    }

    public function test_a_code_merchants_shipment_gets_a_code_the_merchant_sees_and_the_api_does_not(): void
    {
        $plain = $this->shipment();
        $this->assertNull($plain->delivery_code);

        $this->merchantWith(['requires_delivery_code' => true]);
        $shipment = $this->shipment();

        $this->assertMatchesRegularExpression('/^\d{4}$/', $shipment->delivery_code);
        $this->assertArrayNotHasKey('delivery_code', $shipment->toArray());

        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->get($this->host().'/portal/shipments/'.$shipment->id)
            ->assertOk()->assertSee($shipment->delivery_code);

        // المندوب يُسأل عنه ولا يُعطاه
        $this->outForDelivery($shipment);
        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertOk()->assertSee('كود التسليم من الزبون')
            ->assertDontSee('>'.$shipment->delivery_code.'<', false)
            ->assertDontSee('value="'.$shipment->delivery_code.'"', false);
    }

    public function test_the_courier_delivers_only_with_the_right_code(): void
    {
        $this->merchantWith(['requires_delivery_code' => true]);
        $shipment = $this->outForDelivery($this->shipment());
        $wrong = $shipment->delivery_code === '0000' ? '1111' : '0000';

        $this->deliver($shipment, null)->assertSessionHasErrors(['delivery_code' => 'هذه الشحنة تُسلَّم بكود: اطلبه من الزبون.']);
        $this->deliver($shipment, $wrong)->assertSessionHasErrors(['delivery_code' => 'كود التسليم غير صحيح.']);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);

        // يُكتب بالأرقام العربية كما يقرؤه الزبون
        $arabic = strtr($shipment->delivery_code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        $this->deliver($shipment, $arabic)->assertSessionHasNoErrors()->assertRedirect($this->host().'/courier');
        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
    }

    public function test_a_failed_attempt_needs_no_code(): void
    {
        $this->merchantWith(['requires_delivery_code' => true]);
        $shipment = $this->outForDelivery($this->shipment());
        $reason = Tenancy::runFor($this->company, fn () => \App\Models\FailureReason::query()->value('id'));

        $this->actingAs($this->courierUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, ['action' => 'failed_attempt', 'failure_reason_id' => $reason])
            ->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->fresh()->status);
    }

    public function test_five_wrong_codes_lock_the_shipment_even_to_the_right_one(): void
    {
        $this->merchantWith(['requires_delivery_code' => true]);
        $shipment = $this->outForDelivery($this->shipment());
        $wrong = $shipment->delivery_code === '0000' ? '1111' : '0000';

        foreach (range(1, 5) as $_) {
            $this->deliver($shipment, $wrong)->assertSessionHasErrors('delivery_code');
        }

        $this->deliver($shipment, $shipment->delivery_code)
            ->assertSessionHasErrors(['delivery_code' => 'محاولاتٌ كثيرة بكودٍ خاطئ. اتّصل بالشركة.']);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
    }

    // ------------------------------------------------------- تحت المراجعة

    public function test_a_held_merchants_shipments_do_not_go_out_until_approved(): void
    {
        $free = $this->shipment();
        $this->merchantWith(['hold_for_review' => true]);
        $held = $this->shipment();

        $this->assertFalse($free->isHeldForReview());
        $this->assertTrue($held->isHeldForReview());

        // الإسناد الجماعي يتخطّاها ويقول لماذا
        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/assign', ['shipment_ids' => [$free->id, $held->id], 'courier_id' => $this->courier->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, $held->number.' (تحت المراجعة)'));

        $this->assertSame(ShipmentStatus::OutForDelivery, $free->fresh()->status);
        $this->assertSame(ShipmentStatus::PickedUp, $held->fresh()->status);

        // ولا من صفحتها
        Tenancy::runFor($this->company, function () use ($held) {
            try {
                app(ChangeShipmentStatus::class)->handle($held, ShipmentStatus::OutForDelivery, $this->owner,
                    ['courier_id' => $this->courier->id]);
                $this->fail('خرجت شحنةٌ معلَّقة للمراجعة.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });

        $this->actingAs($this->owner)->get($this->host().'/control/review')
            ->assertOk()->assertSee($held->number)->assertDontSee('>'.$free->number.'<', false);
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$held->id)
            ->assertSee('تحت المراجعة:')->assertSee('أجِزها');

        $this->actingAs($this->owner)
            ->post($this->host().'/control/review', ['shipment_ids' => [$held->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'أُجيزت شحنة واحدة'));

        Tenancy::runFor($this->company, function () use ($held) {
            $fresh = $held->fresh();
            $this->assertFalse($fresh->isHeldForReview());
            $this->assertSame($this->owner->id, $fresh->reviewed_by_user_id);
            $this->assertSame(1, ShipmentEvent::where('shipment_id', $held->id)->where('event_type', 'reviewed')->count());
        });

        $this->actingAs($this->owner)->get($this->host().'/control/review')->assertSee('لا شيء ينتظر المراجعة.');

        $this->actingAs($this->owner)
            ->post($this->host().'/shipments/assign', ['shipment_ids' => [$held->id], 'courier_id' => $this->courier->id]);
        $this->assertSame(ShipmentStatus::OutForDelivery, $held->fresh()->status);
    }

    public function test_approving_twice_writes_one_event(): void
    {
        $this->merchantWith(['hold_for_review' => true]);
        $held = $this->shipment();

        $this->actingAs($this->owner)->post($this->host().'/control/review', ['shipment_ids' => [$held->id]]);
        $this->actingAs($this->owner)->post($this->host().'/control/review', ['shipment_ids' => [$held->id]])
            ->assertSessionHas('success', 'لم تُجز أيّ شحنة — قد تكون أُجيزت سلفاً.');

        Tenancy::runFor($this->company, fn () => $this->assertSame(1,
            ShipmentEvent::where('shipment_id', $held->id)->where('event_type', 'reviewed')->count()));
    }

    public function test_reviewing_needs_its_ability(): void
    {
        $this->merchantWith(['hold_for_review' => true]);
        $held = $this->shipment();

        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host().'/control/review')->assertForbidden();
        $this->actingAs($agent)->post($this->host().'/control/review', ['shipment_ids' => [$held->id]])->assertForbidden();

        $manager = $this->makeUser($this->company, UserRole::BranchManager);
        $this->actingAs($manager)->get($this->host().'/control/review')->assertOk()->assertSee($held->number);
    }
}
