<?php

namespace Tests\Feature\Http;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أرقام الحسابات (docs/plan/116): الرقم لا يتكرّر في الشركة، وحين يُرفض يقال لمن هو؛ وحساب التاجر لا يُتخطّى بصمت؛
 * وحساب الدخول يتبع الرقم الجديد؛ والمحذوف لا يحجز رقماً. و«رقمٌ واحد لأكثر من تاجر» من المنصّة يفتح التكرار للتجّار.
 */
class AccountPhonesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchant(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'أزياء الرشيد', 'owner_name' => 'سيف', 'phone' => '07733445566',
            'governorate_id' => $this->baghdad()->id, 'settlement_cycle' => 'weekly', 'payout_method' => 'cash',
            'status' => 'active', 'create_login' => 1, 'password' => 'secret123', 'username' => '',
        ], $overrides);
    }

    private function in(callable $fn): mixed
    {
        return Tenancy::runFor($this->company, $fn);
    }

    private function courierWithLogin(string $phone, string $name = 'أحمد حسين'): User
    {
        return $this->in(function () use ($phone, $name) {
            $courier = Courier::create(['code' => 'C9', 'name' => $name, 'phone' => $phone, 'type' => 'delivery', 'status' => 'active']);

            return User::create(['name' => $name, 'phone' => $phone, 'password' => 'x', 'role' => UserRole::Courier,
                'courier_id' => $courier->id, 'is_active' => true]);
        });
    }

    public function test_a_number_held_by_another_account_is_refused_by_name_and_nothing_is_half_saved(): void
    {
        $this->courierWithLogin('07733445566');

        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchant())
            ->assertSessionHasErrors(['phone' => 'الرقم 07733445566 مسجّلٌ في شركتك لحساب المندوب «أحمد حسين». اكتب رقماً غيره.']);

        // لا تاجرٌ بلا حساب: إمّا الاثنان، وإمّا يقال لماذا
        $this->assertSame(0, $this->in(fn () => Merchant::count()));

        // تاجرٌ آخر بالرقم: باسمه ورمزه
        $this->post($this->host().'/merchants', $this->merchant(['phone' => '07711112222', 'create_login' => 0]))->assertSessionHasNoErrors();
        $first = $this->in(fn () => Merchant::sole());
        $this->post($this->host().'/merchants', $this->merchant(['business_name' => 'متجر ثانٍ', 'phone' => '07711112222', 'create_login' => 0]))
            ->assertSessionHasErrors(['phone' => "الرقم 07711112222 لتاجرٍ آخر في شركتك: «أزياء الرشيد» ({$first->code})."]);

        // واسم مستخدمٍ مأخوذ: لمن هو
        $this->post($this->host().'/merchants', $this->merchant(['phone' => '07799990000', 'username' => '07733445566']))
            ->assertSessionHasErrors(['username' => 'اسم المستخدم «07733445566» لحساب المندوب «أحمد حسين». اختر اسماً غيره، أو اتركه فارغاً.']);
    }

    public function test_a_merchant_login_follows_the_new_number_and_frees_the_old_one(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchant())
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'باسم المستخدم 07733445566'));
        $merchant = $this->in(fn () => Merchant::sole());

        $this->put($this->host().'/merchants/'.$merchant->id, $this->merchant(['phone' => '07700001111', 'create_login' => 0, 'password' => '']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'برقمه الجديد 07700001111'));
        $account = $this->in(fn () => $merchant->fresh()->loginAccount());
        $this->assertSame('07700001111', $account->phone);
        $this->assertSame('07700001111', $account->username);

        // الرقم القديم حرٌّ لتاجرٍ جديدٍ بحسابه
        $this->post($this->host().'/merchants', $this->merchant(['business_name' => 'متجر جديد']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->in(fn () => User::where('role', UserRole::Merchant->value)->count()));

        // ورقمٌ جديدٌ يحمله حسابٌ آخر: لا يتبعه الحساب ولا يُحفظ
        $this->courierWithLogin('07722223333');
        $this->put($this->host().'/merchants/'.$merchant->id, $this->merchant(['phone' => '07722223333', 'create_login' => 0, 'password' => '']))
            ->assertSessionHasErrors('phone');
        $this->assertSame('07700001111', $this->in(fn () => $merchant->fresh()->phone));
    }

    public function test_a_deleted_account_does_not_hold_its_number_or_name(): void
    {
        $this->in(fn () => User::create(['name' => 'موظّف ترك العمل', 'phone' => '07733445566', 'password' => 'x',
            'role' => UserRole::Operations, 'is_active' => true])->delete());

        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchant())->assertSessionHasNoErrors();
        $this->assertSame('07733445566', $this->in(fn () => Merchant::sole()->loginAccount()->username));
        $this->assertSame('removed-'.$this->in(fn () => User::onlyTrashed()->sole()->id), $this->in(fn () => User::onlyTrashed()->sole()->username));
    }

    public function test_with_shared_numbers_from_the_platform_a_merchant_shares_a_number_and_logs_in_by_its_own_name(): void
    {
        $this->setFeature($this->company, Feature::SharedPhones);
        $this->courierWithLogin('07733445566');

        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchant())->assertSessionHasNoErrors();
        $first = $this->in(fn () => Merchant::sole());
        $account = $this->in(fn () => $first->loginAccount());
        $this->assertSame('07733445566', $account->phone);
        $this->assertSame('07733445566-'.strtolower($first->code), $account->username);

        // ومتجرٌ ثانٍ لصاحبه بالرقم نفسه
        $this->post($this->host().'/merchants', $this->merchant(['business_name' => 'فرع الرشيد الثاني']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->in(fn () => Merchant::where('phone', '07733445566')->count()));
        $this->assertSame(3, $this->in(fn () => User::where('phone', '07733445566')->count()));

        // واسم المستخدم لا يتكرّر أبداً
        $this->post($this->host().'/merchants', $this->merchant(['business_name' => 'ثالث', 'username' => $account->username]))
            ->assertSessionHasErrors('username');

        // ومطفأةً يعود المنع
        $this->setFeature($this->company, Feature::SharedPhones, false);
        $this->post($this->host().'/merchants', $this->merchant(['business_name' => 'رابع', 'create_login' => 0]))
            ->assertSessionHasErrors('phone');
    }

    public function test_couriers_and_staff_are_told_who_holds_the_number(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchant())->assertSessionHasNoErrors();

        $this->post($this->host().'/couriers', ['name' => 'سجاد', 'phone' => '07733445566', 'type' => 'pickup', 'vehicle_type' => 'van',
            'commission_per_pickup' => 800, 'cash_limit' => 0, 'status' => 'active', 'create_login' => 1, 'password' => 'secret123'])
            ->assertSessionHasErrors(['phone' => 'الرقم 07733445566 مسجّلٌ في شركتك لحساب دخول التاجر «أزياء الرشيد». اكتب رقماً غيره.']);
        $this->assertSame(0, $this->in(fn () => Courier::count()));

        $this->post($this->host().'/users', ['name' => 'موظّف', 'phone' => '07733445566', 'role' => 'operations',
            'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertSessionHasErrors(['phone' => 'الرقم 07733445566 مسجّلٌ في شركتك لحساب دخول التاجر «أزياء الرشيد». اكتب رقماً غيره.']);
    }
}
