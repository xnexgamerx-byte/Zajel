<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * دخول التطبيق الموحّد (docs/plan/114، 115): يرسل أقسامه (التاجر والكول سنتر والمندوب)، فيدخل كلّ حسابٍ قسمَ دوره
 * ويعود اسمه فيُفتح، ورمزه لا يفتح غير قسمه. ونسخة المرحلة الأولى (بلا قسم المندوب) تقول للمندوب أن يحدّثها.
 * والتطبيقات المنفصلة تدخل كما كانت.
 */
class UnifiedAppLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = ['merchant', 'callcenter', 'courier'];

    /** نسخة التطبيق الموحّد من المرحلة الأولى (docs/plan/114) */
    private const PHASE_ONE = ['merchant', 'callcenter'];

    private Company $company;

    private User $merchantUser;

    private User $sara;

    private Courier $courier;

    private User $courierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company);
        Tenancy::runFor($this->company, function () use ($merchant) {
            $this->merchantUser = User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true]);
            $this->sara = User::create(['name' => 'سارة', 'phone' => '07701110011', 'password' => 'password',
                'role' => UserRole::CustomerService, 'is_active' => true]);
            $this->courier = Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $this->courierUser = User::create(['name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true]);
        });
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function login(User $user, array $body = ['apps' => self::SECTIONS]): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($this->api('/login'), ['username' => $user->username, 'password' => 'password', 'device' => 'phone'] + $body);
    }

    private function bearer(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_a_merchant_opens_the_merchant_section_and_its_token_opens_nothing_else(): void
    {
        $token = $this->login($this->merchantUser)->assertOk()
            ->assertJsonPath('app', 'merchant')->assertJsonPath('user.role', 'merchant')->json('token');

        $this->getJson($this->api('/me'), $this->bearer($token))->assertOk()->assertJsonPath('app', 'merchant');
        $this->getJson($this->api('/merchant/home'), $this->bearer($token))->assertOk();
        $this->getJson($this->api('/callcenter/home'), $this->bearer($token))->assertForbidden();
        $this->getJson($this->api('/courier/home'), $this->bearer($token))->assertForbidden();
    }

    public function test_call_centre_staff_open_the_call_centre_section(): void
    {
        $token = $this->login($this->sara)->assertOk()
            ->assertJsonPath('app', 'callcenter')->assertJsonPath('merchant', null)->json('token');

        $this->getJson($this->api('/me'), $this->bearer($token))->assertOk()->assertJsonPath('app', 'callcenter');
        $this->getJson($this->api('/callcenter/home'), $this->bearer($token))->assertOk();
        $this->getJson($this->api('/merchant/home'), $this->bearer($token))->assertForbidden();
        $this->getJson($this->api('/courier/home'), $this->bearer($token))->assertForbidden();
    }

    public function test_a_courier_opens_the_courier_section_and_a_suspended_or_partner_one_does_not(): void
    {
        $token = $this->login($this->courierUser)->assertOk()
            ->assertJsonPath('app', 'courier')->assertJsonPath('user.role', 'courier')->json('token');

        $this->getJson($this->api('/me'), $this->bearer($token))->assertOk()->assertJsonPath('app', 'courier');
        $this->getJson($this->api('/courier/home'), $this->bearer($token))->assertOk();
        $this->getJson($this->api('/merchant/home'), $this->bearer($token))->assertForbidden();
        $this->getJson($this->api('/callcenter/home'), $this->bearer($token))->assertForbidden();

        // مندوبٌ موقوف، أو شركةٌ وسيطة تعمل بمفتاح الربط: كما في تطبيق المندوب
        Tenancy::runFor($this->company, fn () => $this->courier->forceFill(['status' => 'suspended'])->save());
        $this->login($this->courierUser)->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'حسابك موقوف أو غير مرتبط بمندوب. راجع الشركة.');
        Tenancy::runFor($this->company, fn () => $this->courier->forceFill(['status' => 'active', 'is_intermediary' => true])->save());
        $this->login($this->courierUser)->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'حساب الشركة الوسيطة يعمل بمفتاح الربط، لا بتطبيق المندوب.');
        $this->assertSame(1, $this->courierUser->tokens()->count());
    }

    public function test_who_has_no_section_here_is_told_where_to_go(): void
    {
        // المحاسب لا يعالج شيئاً في الكول سنتر
        $accountant = Tenancy::runFor($this->company, fn () => User::create(['name' => 'محاسب', 'phone' => '07701110013',
            'password' => 'password', 'role' => UserRole::Accountant, 'is_active' => true]));
        $this->login($accountant)->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'حسابك لا يعالج التذاكر ولا الشحنات ولا المحادثات. اطلب الصلاحية من مدير الشركة.');

        // المندوب في نسخة المرحلة الأولى: يحدّثها، أو يدخل من تطبيقه
        $this->login($this->courierUser, ['apps' => self::PHASE_ONE])->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'حدّث التطبيق ليدخل منه المندوب، أو ادخل من تطبيق المندوب.');
        $this->assertSame(0, $this->courierUser->tokens()->count());

        // ونسخة المرحلة الأولى تبقى تُدخل التاجر وموظّفة الكول سنتر
        $this->login($this->merchantUser, ['apps' => self::PHASE_ONE])->assertOk()->assertJsonPath('app', 'merchant');
        $this->login($this->sara, ['apps' => self::PHASE_ONE])->assertOk()->assertJsonPath('app', 'callcenter');

        // كلمة مرورٍ خاطئة كما كانت
        $this->postJson($this->api('/login'), ['username' => $this->sara->username, 'password' => 'wrong', 'apps' => self::SECTIONS])
            ->assertUnprocessable()->assertJsonPath('errors.username.0', 'اسم المستخدم أو كلمة المرور غير صحيحة.');
    }

    public function test_the_separate_apps_still_log_in_as_before_and_bad_sections_are_refused(): void
    {
        $token = $this->login($this->merchantUser, ['app' => 'merchant'])->assertOk()->assertJsonPath('app', 'merchant')->json('token');
        $this->getJson($this->api('/me'), $this->bearer($token))->assertJsonPath('app', 'merchant');
        $this->login($this->sara, ['app' => 'callcenter'])->assertOk()->assertJsonPath('app', 'callcenter');
        $this->login($this->sara, ['app' => 'merchant'])->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'هذا التطبيق للتجّار. ادخل من تطبيقك أو من الموقع.');
        $this->login($this->courierUser, ['app' => 'courier'])->assertOk()->assertJsonPath('app', 'courier');

        // بلا قسم، أو بقسمٍ لا يُعرف
        $this->login($this->sara, [])->assertUnprocessable()->assertJsonValidationErrors(['app', 'apps']);
        $this->login($this->sara, ['apps' => ['admin']])->assertUnprocessable()->assertJsonValidationErrors('apps.0');
        $this->login($this->sara, ['apps' => 'callcenter'])->assertUnprocessable()->assertJsonValidationErrors('apps');
        $this->assertSame(1, $this->sara->tokens()->count());
    }
}
