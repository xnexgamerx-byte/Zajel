<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحةٌ بقيت مفتوحةً على الهاتف حتى انتهت الجلسة (docs/plan/29): «حفظ» لا يضيّع ما
 * كُتب ولا يرمي إلى «الصفحة الرئيسية» — يعود إلى الصفحة بما فيها، وبعد الدخول إن خرج.
 */
class ExpiredFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $merchantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company, 'M0001');

        $this->merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function form(): string
    {
        return $this->host().'/portal/shipments/create';
    }

    private function order(array $extra = []): array
    {
        return $extra + [
            'recipient_name' => 'زبون بعد ساعتين', 'recipient_phone' => '07712345678',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور'),
            'pieces_count' => 1, 'cod_amount' => 30_000, 'notes' => 'ملاحظة مهمّة',
        ];
    }

    /** فحص الرمز كما على الخادم: الاختبارات تتخطّاه عادةً */
    private function checkTokens(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function shipments(): int
    {
        return Tenancy::runFor($this->company, fn () => Shipment::count());
    }

    public function test_signed_out_by_an_expired_session_the_order_comes_back_after_login(): void
    {
        // على HTTPS يمرّ الطلب من فحص الرمز (Sec-Fetch-Site) ويصل إلى «سجّل الدخول»
        $this->withHeader('Referer', $this->form())
            ->post($this->host().'/portal/shipments', $this->order())
            ->assertRedirect($this->host().'/login')
            ->assertSessionHas('relogin');

        $this->get($this->host().'/login')->assertSee('انتهت جلستك والصفحة مفتوحة');

        $this->followingRedirects()
            ->post($this->host().'/login', ['username' => '07790000001', 'password' => 'password'])
            ->assertOk()
            ->assertSee('شحنة جديدة')
            ->assertSee('value="زبون بعد ساعتين"', false)
            ->assertSee('ملاحظة مهمّة')
            ->assertSee('ما كتبته قبل انتهاء الجلسة عاد إلى الصفحة ولم يُحفظ بعد');

        $this->assertSame(0, $this->shipments());
    }

    public function test_after_login_the_order_returns_to_the_page_it_was_typed_in(): void
    {
        $this->withHeader('Referer', $this->form())->post($this->host().'/portal/shipments', $this->order());

        $this->post($this->host().'/login', ['username' => '07790000001', 'password' => 'password'])
            ->assertRedirect($this->form())
            ->assertSessionHasErrors('session');

        $this->assertSame('زبون بعد ساعتين', session()->getOldInput('recipient_name'));
        $this->assertNull(session('expired_form'));
    }

    public function test_a_stale_page_while_still_signed_in_returns_to_the_form_with_its_input(): void
    {
        $this->checkTokens();

        $this->actingAs($this->merchantUser)
            ->withHeader('Referer', $this->form())
            ->post($this->host().'/portal/shipments', $this->order(['_token' => 'رمزٌ قديم']))
            ->assertRedirect($this->form())
            ->assertSessionHasErrors(['session' => 'انتهت الجلسة والصفحة مفتوحة، فلم يُحفظ شيء. ما كتبته باقٍ — اضغط «حفظ» مرّةً أخرى.']);

        $this->assertSame('زبون بعد ساعتين', session()->getOldInput('recipient_name'));
        $this->assertNull(session()->getOldInput('_token'));
        $this->assertSame(0, $this->shipments());

        // والصفحة التي يعود إليها تقول ذلك
        $this->followingRedirects()->actingAs($this->merchantUser)
            ->withHeader('Referer', $this->form())
            ->post($this->host().'/portal/shipments', $this->order(['_token' => 'رمزٌ قديم']))
            ->assertOk()
            ->assertSee('value="زبون بعد ساعتين"', false)
            ->assertSee('ما كتبته باقٍ — اضغط «حفظ» مرّةً أخرى.');
    }

    public function test_a_stale_page_after_signing_out_goes_through_login_and_back(): void
    {
        $this->checkTokens();

        $this->withHeader('Referer', $this->form())
            ->post($this->host().'/portal/shipments', $this->order(['_token' => 'رمزٌ قديم']))
            ->assertRedirect($this->host().'/login')
            ->assertSessionHas('relogin')
            ->assertSessionHas('url.intended', $this->form());
    }

    public function test_the_kept_order_is_dropped_if_login_comes_much_later(): void
    {
        $this->withHeader('Referer', $this->form())->post($this->host().'/portal/shipments', $this->order());

        // جهازٌ مشترك: من يدخل بعد ساعةٍ لا يرى ما كتبه غيره
        $this->travel(11)->minutes();

        $this->post($this->host().'/login', ['username' => '07790000001', 'password' => 'password'])
            ->assertRedirect($this->form())
            ->assertSessionDoesntHaveErrors();

        $this->assertNull(session()->getOldInput('recipient_name'));
    }

    public function test_a_login_page_left_open_too_long_asks_to_press_login_again(): void
    {
        $this->checkTokens();

        $this->withHeader('Referer', $this->host().'/login')
            ->post($this->host().'/login', ['_token' => 'x', 'username' => '07790000001', 'password' => 'password'])
            ->assertRedirect($this->host().'/login')
            ->assertSessionHas('relogin', 'مرّ وقتٌ طويل على صفحة الدخول — اضغط «دخول» مرّةً أخرى.');

        $this->assertSame('07790000001', session()->getOldInput('username'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertGuest();
    }

    public function test_a_post_from_another_site_is_neither_sent_back_nor_refilled(): void
    {
        $this->checkTokens();

        $this->withHeader('Referer', 'https://evil.example/form')
            ->post($this->host().'/portal/shipments', $this->order(['_token' => 'x']))
            ->assertStatus(419);

        // ولا من صفحة شركةٍ أخرى على المنصّة
        $this->withHeader('Referer', 'http://other.'.config('zajel.tenant_domain').'/portal/shipments/create')
            ->post($this->host().'/portal/shipments', $this->order(['_token' => 'x']))
            ->assertStatus(419);

        $this->assertNull(session('expired_form'));
    }

    public function test_passwords_are_never_kept(): void
    {
        $this->withHeader('Referer', $this->host().'/portal/account')
            ->post($this->host().'/portal/shipments', $this->order(['password' => 'سرّ', 'current_password' => 'سرّ']));

        $kept = session('expired_form.input');

        $this->assertArrayNotHasKey('password', $kept);
        $this->assertArrayNotHasKey('current_password', $kept);
        $this->assertSame('زبون بعد ساعتين', $kept['recipient_name']);
    }

    public function test_a_json_request_is_answered_as_json(): void
    {
        $this->checkTokens();

        $this->withHeader('Referer', $this->form())
            ->postJson($this->host().'/portal/shipments', $this->order(['_token' => 'x']))
            ->assertStatus(419);

        $this->assertNull(session('expired_form'));
    }

    public function test_the_kept_order_returns_only_to_its_own_page(): void
    {
        $this->withHeader('Referer', $this->form())
            ->post($this->host().'/portal/shipments', $this->order());

        // دخل ثم وُجِّه إلى غيرها (فُتح الدخول من رابطٍ آخر): لا يُملأ به نموذجٌ آخر
        session()->put('url.intended', $this->host().'/portal/statement');

        $this->post($this->host().'/login', ['username' => '07790000001', 'password' => 'password'])
            ->assertRedirect($this->host().'/portal/statement');

        $this->assertNull(session()->getOldInput('recipient_name'));
        $this->assertNull(session('expired_form'));
    }
}
