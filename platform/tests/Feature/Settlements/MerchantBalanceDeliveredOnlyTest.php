<?php

namespace Tests\Feature\Settlements;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
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
 * حساب التاجر في التطبيق والبوابة للواصل وحده (docs/plan/60): الإجمالي ما وصل لزبائنه، والمتاح
 * للسحب ما حاسبت الشركةُ مندوبَه عليه، والباقي «قيد المطابقة» ما زال مع المندوب. وما لم يصل
 * بعد — جديدٌ أو في الطريق — لا يُحسب له شيئاً.
 */
class MerchantBalanceDeliveredOnlyTest extends TestCase
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

    private function courier(string $code): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => $code, 'name' => 'مندوب '.$code, 'phone' => '0772000000'.substr($code, 1), 'type' => 'delivery',
            'status' => 'active', 'commission_per_delivery' => 3000,
        ]));
    }

    /** @param  list<ShipmentStatus>  $path */
    private function shipment(int $amount, array $path, ?Courier $courier = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($amount, $path, $courier) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $amount,
            ], $this->owner);
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier?->id : null]);
            }

            return $shipment->refresh();
        });
    }

    public function test_only_delivered_money_counts_and_only_what_the_courier_handed_over_is_available(): void
    {
        $settled = $this->courier('C1');
        $holding = $this->courier('C2');
        $toDoor = [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered];

        $paid = $this->shipment(1_000_000, $toDoor, $settled);
        $held = $this->shipment(200_000, $toDoor, $holding);
        // لم تصل بعد: لا شيء منها في حسابه
        $this->shipment(500_000, []);
        $this->shipment(300_000, [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery], $holding);

        // الشركة حاسبت المندوب الأوّل فقط
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle(
            app(BuildCourierSettlement::class)->handle($settled->refresh(), $this->owner), $this->owner,
        ));

        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
        $api = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1';
        $token = $this->postJson($api.'/login', ['username' => $user->username, 'password' => 'password', 'app' => 'merchant'])
            ->assertOk()->json('token');

        $balance = $this->withToken($token)->getJson($api.'/merchant/home')->assertOk()->json('balance');

        $this->assertSame($paid->merchant_due + $held->merchant_due, $balance['total']);
        $this->assertSame($paid->merchant_due, $balance['available']);
        $this->assertSame($held->merchant_due, $balance['pending']);
        $this->assertSame(1, $balance['pending_count']);
        // والمالية في التطبيق تقول الشيء نفسه
        $this->withToken($token)->getJson($api.'/merchant/finance')->assertOk()
            ->assertJsonPath('balance.total', $balance['total'])
            ->assertJsonPath('balance.available', $balance['available']);
    }
}
