<?php

namespace Tests\Feature\Api;

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
 * واجهة تطبيق التاجر (docs/plan/48): دخولٌ برمز، ورئيسيةٌ بأرقام البوابة نفسها،
 * وكل شركةٍ لا ترى إلا نفسها.
 */
class MerchantApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['owner_name' => 'أحمد']));
        $this->staff = $this->makeUser($this->company);
        $this->user = $this->merchantUser($this->company, $this->merchant);
    }

    private function api(string $path, ?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function merchantUser(Company $company, Merchant $merchant): User
    {
        return Tenancy::runFor($company, fn () => User::create([
            'name' => 'تاجر', 'phone' => $this->phoneFrom($company->slug.'merchant', '0779'),
            'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    private function login(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant', ...$overrides,
        ]);
    }

    private function token(): string
    {
        return $this->login()->assertOk()->json('token');
    }

    private function shipment(string $recipient, ShipmentStatus $status = ShipmentStatus::Created): Shipment
    {
        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => $recipient,
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'city_id'         => $this->area('المنصور'),
            'address'         => 'بغداد',
            'cod_amount'      => 45_000,
        ], $this->staff));

        if ($status !== ShipmentStatus::Created) {
            Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status' => $status->value, 'status_changed_at' => now()])->save());
        }

        return $shipment;
    }

    public function test_a_merchant_logs_in_and_gets_a_token_with_the_company_brand(): void
    {
        $this->login()
            ->assertOk()
            ->assertJsonPath('user.name', 'أحمد')
            ->assertJsonPath('user.role', 'merchant')
            ->assertJsonPath('company.name', 'الزاجل')
            ->assertJsonStructure(['token', 'merchant' => ['store', 'can_process'], 'company' => ['initial', 'color', 'logo']]);
    }

    public function test_the_login_screen_can_read_the_company_before_anyone_logs_in(): void
    {
        $this->getJson($this->api('/company'))->assertOk()->assertJsonPath('company.name', 'الزاجل');
    }

    public function test_a_wrong_password_is_refused_and_counted(): void
    {
        $this->login(['password' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_staff_cannot_log_into_the_merchant_app(): void
    {
        $this->postJson($this->api('/login'), [
            'username' => $this->staff->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertUnprocessable();
    }

    public function test_a_merchant_without_portal_access_is_refused_at_the_door(): void
    {
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['portal_access' => false]));

        $this->login()->assertUnprocessable();
    }

    public function test_another_companys_app_does_not_know_this_account(): void
    {
        $other = $this->makeCompany('barq', 'البرق');

        $this->postJson($this->api('/login', $other), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertUnprocessable();
    }

    public function test_a_token_from_one_company_does_not_open_another(): void
    {
        $token = $this->token();
        $other = $this->makeCompany('barq', 'البرق');

        $this->withToken($token)->getJson($this->api('/merchant/home', $other))->assertUnauthorized();
    }

    public function test_home_without_a_token_is_unauthorised(): void
    {
        $this->getJson($this->api('/merchant/home'))->assertUnauthorized();
    }

    public function test_home_carries_the_merchants_numbers(): void
    {
        $this->shipment('محمد علي', ShipmentStatus::Delivered);
        $this->shipment('نور خالد', ShipmentStatus::OutForDelivery);
        $this->shipment('سارة أحمد', ShipmentStatus::FailedAttempt);
        $this->shipment('زبون مؤجل', ShipmentStatus::Postponed);
        $this->shipment('زبون راجع', ShipmentStatus::Returned);

        $home = $this->withToken($this->token())->getJson($this->api('/merchant/home'))
            ->assertOk()
            ->assertJsonPath('name', 'أحمد')
            ->assertJsonPath('stats.total', 5)
            ->assertJsonPath('stats.delivered', 1)
            ->assertJsonPath('stats.in_delivery', 3)
            ->assertJsonPath('stats.returns', 1)
            ->assertJsonPath('processing.count', 1)
            ->assertJsonPath('attention.count', 1);

        // ما يحتاج قراره أوّلاً: «للمعالجة» بالأحمر قبل الواصل
        $recent = collect($home->json('recent'));
        $this->assertSame('للمعالجة', $recent->firstWhere('name', 'سارة أحمد')['status']);
        $this->assertTrue($recent->firstWhere('name', 'سارة أحمد')['urgent']);
        $this->assertFalse($recent->firstWhere('name', 'محمد علي')['urgent']);
        $this->assertTrue($recent->first()['urgent']);
        $this->assertSame('المنصور', $recent->first()['area']);
    }

    public function test_home_never_shows_another_merchants_shipments(): void
    {
        $beta = $this->makeMerchant($this->company, 'M0002');
        Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $beta->id, 'recipient_name' => 'زبون غريب', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور'),
            'address' => 'بغداد', 'cod_amount' => 1000,
        ], $this->staff));

        $this->withToken($this->token())->getJson($this->api('/merchant/home'))
            ->assertOk()
            ->assertJsonPath('stats.total', 0)
            ->assertJsonMissing(['name' => 'زبون غريب']);
    }

    public function test_a_suspended_account_loses_its_token(): void
    {
        $token = $this->token();
        Tenancy::runFor($this->company, fn () => $this->user->update(['is_active' => false]));

        $this->withToken($token)->getJson($this->api('/merchant/home'))->assertUnauthorized();
    }

    public function test_logout_revokes_only_this_devices_token(): void
    {
        $phone = $this->token();
        $tablet = $this->token();

        $this->withToken($phone)->postJson($this->api('/logout'))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($phone)->getJson($this->api('/me'))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson($this->api('/me'))->assertOk();
    }

    public function test_a_courier_token_cannot_open_the_merchant_home(): void
    {
        $token = Tenancy::runFor($this->company, fn () => $this->user->createToken('courier:x', ['courier'])->plainTextToken);

        $this->withToken($token)->getJson($this->api('/merchant/home'))->assertForbidden();
    }

    // ------------------------------------------------------------ شحناتي

    public function test_the_shipment_list_filters_match_the_home_counters(): void
    {
        $this->shipment('محمد علي', ShipmentStatus::Delivered);
        $this->shipment('نور خالد', ShipmentStatus::OutForDelivery);
        $this->shipment('سارة أحمد', ShipmentStatus::FailedAttempt);
        $this->shipment('زبون راجع', ShipmentStatus::Returned);

        $token = $this->token();
        $list = $this->withToken($token)->getJson($this->api('/merchant/shipments'))->assertOk()
            ->assertJsonPath('meta.total', 4);
        $chips = collect($list->json('filters'))->pluck('count', 'key');
        $this->assertSame(['all' => 4, 'open' => 2, 'delivered' => 1, 'processing' => 1, 'attention' => 0, 'returns' => 1], $chips->all());

        $this->withToken($token)->getJson($this->api('/merchant/shipments?filter=delivered'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'محمد علي');
        $this->withToken($token)->getJson($this->api('/merchant/shipments?filter=processing'))->assertOk()
            ->assertJsonPath('data.0.status', 'للمعالجة')->assertJsonPath('data.0.tone', 'red');
    }

    public function test_the_shipment_list_searches_by_name_and_number(): void
    {
        $found = $this->shipment('زينب كاظم');
        $this->shipment('علي حسين');

        $token = $this->token();
        $this->withToken($token)->getJson($this->api('/merchant/shipments?q=زينب'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $found->number);
        $this->withToken($token)->getJson($this->api('/merchant/shipments?q='.$found->number))->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_shipment_detail_carries_its_path_customer_and_account(): void
    {
        $shipment = $this->shipment('زينب كاظم', ShipmentStatus::OutForDelivery);

        $this->withToken($this->token())->getJson($this->api('/merchant/shipments/'.$shipment->id))->assertOk()
            ->assertJsonPath('number', $shipment->number)
            ->assertJsonPath('recipient.name', 'زينب كاظم')
            ->assertJsonPath('recipient.city', 'المنصور')
            ->assertJsonPath('money.cod', 45000)
            ->assertJsonStructure(['timeline' => [['title', 'at']], 'tracking_url', 'money' => ['delivery_fee', 'due', 'owed']]);
    }

    public function test_a_merchant_cannot_open_another_merchants_shipment_in_the_app(): void
    {
        $beta = $this->makeMerchant($this->company, 'M0002');
        $foreign = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $beta->id, 'recipient_name' => 'زبون غريب', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور'),
            'address' => 'بغداد', 'cod_amount' => 1000,
        ], $this->staff));

        $token = $this->token();
        $this->withToken($token)->getJson($this->api('/merchant/shipments/'.$foreign->id))->assertNotFound();
        $this->withToken($token)->getJson($this->api('/merchant/shipments?q=غريب'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ------------------------------------------------------------ المتاح للسحب

    public function test_money_with_the_courier_is_pending_not_available(): void
    {
        $paid = $this->shipment('واصلة محاسَبة', ShipmentStatus::Delivered);
        $held = $this->shipment('واصلة مع المندوب', ShipmentStatus::Delivered);
        $courier = Tenancy::runFor($this->company, fn () => \App\Models\Courier::create([
            'code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));

        Tenancy::runFor($this->company, function () use ($paid, $held, $courier) {
            foreach ([$paid, $held] as $s) {
                $s->forceFill(['delivered_at' => now(), 'delivery_courier_id' => $courier->id, 'merchant_due' => 40000])->save();
            }
            $paid->forceFill(['courier_settled_at' => now()])->save();
            $this->merchant->forceFill(['balance' => 80000])->save();
        });

        $this->withToken($this->token())->getJson($this->api('/merchant/home'))->assertOk()
            ->assertJsonPath('balance.total', 80000)
            ->assertJsonPath('balance.available', 40000)
            ->assertJsonPath('balance.pending', 40000)
            ->assertJsonPath('balance.pending_count', 1);
    }
}
