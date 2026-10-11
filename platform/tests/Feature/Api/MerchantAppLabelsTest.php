<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «طباعة الوصولات» من تطبيق التاجر (docs/plan/63): طلباته المدخلة، يحدّدها ويختار المقاس، فيُفتح
 * رابطٌ موقَّعٌ يطبعها — شحناته وحدها، وبالمقاس المختار.
 */
class MerchantAppLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Merchant $other;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->other = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000001',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function headers(): array
    {
        $token = $this->postJson($this->host().'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function ship(Merchant $merchant, string $name, bool $pickedUp = false): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $name, $pickedUp) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
            ], $this->staff);
            if ($pickedUp) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            }

            return $shipment->refresh();
        });
    }

    public function test_the_merchant_prints_his_entered_orders_in_the_size_he_picks(): void
    {
        $a = $this->ship($this->merchant, 'علي');
        $b = $this->ship($this->merchant, 'زينب');
        $this->ship($this->merchant, 'استُلمت', pickedUp: true);
        $theirs = $this->ship($this->other, 'غيره');
        $headers = $this->headers();

        // المدخلة وحدها، الأحدث أوّلاً، والمقاسات الأربعة
        $this->getJson($this->host().'/api/v1/merchant/labels', $headers)->assertOk()
            ->assertJsonCount(2, 'shipments')
            ->assertJsonPath('shipments.0.name', 'زينب')
            ->assertJsonPath('shipments.1.number', $a->number)
            ->assertJsonCount(4, 'sizes')
            ->assertJsonPath('sizes.1.key', '80x120');

        // شحنة غيره لا تدخل الرابط
        $url = $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id, $b->id, $theirs->id], 'size' => '100x100'], $headers)
            ->assertCreated()->assertJsonPath('count', 2)->json('url');

        $this->get($url)->assertOk()
            ->assertSee($a->number)->assertSee($b->number)->assertDontSee($theirs->number)
            ->assertSee('100mm 100mm', false)->assertSee('ملصق ٨×١٢');

        // الرابط موقَّع: يُغيَّر فيه شيءٌ فلا يُفتح
        $this->get(str_replace('size=100x100', 'size=a4', $url))->assertForbidden();

        $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$a->id], 'size' => 'huge'], $headers)
            ->assertUnprocessable();
        $this->postJson($this->host().'/api/v1/merchant/labels', ['ids' => [$theirs->id], 'size' => 'a6'], $headers)
            ->assertUnprocessable();
    }
}
