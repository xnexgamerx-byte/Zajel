<?php

namespace Tests\Feature\Api;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «للمعالجة» و«المالية» في تطبيق التاجر (docs/plan/54): قرار التاجر في شحنته بطريق البوابة،
 * وحسابه وكشوفه وطلب محاسبته بأرقامها نفسها.
 */
class MerchantAppMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        [$this->user, $this->courier] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $this->merchant->id, 'is_active' => true]),
            Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
        ]);
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function headers(): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function shipment(int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $cod,
        ], $this->owner));
    }

    private function failed(): Shipment
    {
        $shipment = $this->shipment();
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => 'failed_attempt', 'delivery_courier_id' => $this->courier->id,
            'status_changed_at' => now()->subHours(5), 'attempts_count' => 1,
        ])->save());

        return $shipment;
    }

    private function deliver(int $cod): Shipment
    {
        $shipment = $this->shipment($cod);

        return Tenancy::runFor($this->company, function () use ($shipment) {
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    public function test_the_merchant_decides_a_failed_shipment_when_allowed(): void
    {
        $shipment = $this->failed();
        $headers = $this->headers();

        // لم تُفعَّل له المعالجة: يراها ولا يقرّر
        $this->getJson($this->api('/merchant/processing'), $headers)->assertOk()
            ->assertJsonPath('allowed', false)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.phone', '07801234567')->assertJsonPath('data.0.attempts', 1);
        $this->postJson($this->api('/merchant/processing/'.$shipment->id), ['action' => 'redeliver'], $headers)->assertForbidden();

        Tenancy::runFor($this->company, fn () => $this->merchant->update(['can_process' => true]));

        $this->postJson($this->api('/merchant/processing/'.$shipment->id), ['action' => 'postpone'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('until');
        $this->postJson($this->api('/merchant/processing/'.$shipment->id), [
            'action' => 'postpone', 'until' => now()->addDays(2)->toDateString(), 'note' => 'بعد يومين',
        ], $headers)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'مؤجل'));

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(ShipmentStatus::Postponed, $shipment->refresh()->status);
            $this->assertSame('merchant', $shipment->events()->where('event_type', 'processed')->sole()->meta['by']);
        });

        // شحنة تاجرٍ آخر لا تُمسّ
        $other = $this->makeMerchant($this->company, 'M0002');
        $theirs = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $other->id, 'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
            'address' => 'بغداد', 'cod_amount' => 1000,
        ], $this->owner));
        $this->postJson($this->api('/merchant/processing/'.$theirs->id), ['action' => 'return'], $headers)->assertNotFound();
    }

    public function test_finance_shows_the_balance_statements_and_movements(): void
    {
        $this->deliver(50_000);
        $this->deliver(30_000);
        $this->settleCourierCash($this->company);
        $settlement = Tenancy::runFor($this->company, function () {
            $s = app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner);
            $s = app(PayMerchantSettlement::class)->confirm($s, $this->owner);

            return app(PayMerchantSettlement::class)->pay($s, $this->owner, 'zaincash', 'TX-1');
        });
        $this->deliver(20_000);
        $headers = $this->headers();

        $this->getJson($this->api('/merchant/finance'), $headers)->assertOk()
            ->assertJsonPath('balance.total', 15_000)->assertJsonPath('balance.pending', 15_000)
            ->assertJsonPath('statements.0.code', $settlement->code)
            ->assertJsonPath('statements.0.status', 'مدفوع')->assertJsonPath('statements.0.net', 70_000)
            ->assertJsonPath('statements.0.confirmed', false)
            ->assertJsonFragment(['value' => 'zaincash', 'label' => 'زين كاش', 'details' => true])
            ->assertJsonPath('movements.0.amount', 15_000);

        // «استلمتُها» مرّةً واحدة
        $this->postJson($this->api('/merchant/finance/statements/'.$settlement->id.'/confirm'), [], $headers)->assertOk();
        $this->postJson($this->api('/merchant/finance/statements/'.$settlement->id.'/confirm'), [], $headers)->assertStatus(422);
    }

    public function test_a_payment_request_follows_the_portal_rules(): void
    {
        $headers = $this->headers();
        $this->deliver(50_000);

        // نقده مع المندوب بعد: لا متاح
        $this->postJson($this->api('/merchant/finance/request'), ['payout_method' => 'cash'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $this->settleCourierCash($this->company);

        // محفظةٌ بلا رقم
        $this->postJson($this->api('/merchant/finance/request'), ['payout_method' => 'zaincash'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('payout_details');

        $this->postJson($this->api('/merchant/finance/request'), [
            'payout_method' => 'zaincash', 'payout_details' => '07801112222 — أحمد',
        ], $headers)->assertCreated()->assertJsonPath('amount', 45_000)->assertJsonPath('method', 'زين كاش');

        $this->getJson($this->api('/merchant/finance'), $headers)->assertJsonPath('request.amount', 45_000);
        // طلبٌ مفتوح واحد
        $this->postJson($this->api('/merchant/finance/request'), ['payout_method' => 'cash'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => MerchantRequest::count()));
    }
}
