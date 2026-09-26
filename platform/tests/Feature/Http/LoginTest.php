<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدخول باسم المستخدم: اسمٌ واحد مهما كُتب، ومحاولاتٌ معدودة على الحساب.
 *
 * MySQL (utf8mb4_unicode_ci) ترى «ALI» و«ａｌｉ» و«٠٧٧٠…» مساويةً لـ «ali»
 * و«0770…»: لو كان مفتاح المحاولات النصَّ كما كُتب لكان لكل صيغةٍ خمسُ
 * محاولاتٍ جديدة. هذه الاختبارات تُسقط الثغرة على المحرّكين: على MySQL
 * تدخل الصيغة الأخرى متجاوزةً الحدّ، وعلى SQLite لا تُعرف أصلاً.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->user = $this->makeUser($this->company, UserRole::CompanyAdmin);
    }

    private function host(): string
    {
        return 'http://zajel.'.config('zajel.tenant_domain');
    }

    /** 07… باللاتينية إلى الأرقام العربية-الهندية كما تكتبها لوحة المفاتيح العربية. */
    private function arabicDigits(string $phone): string
    {
        return strtr($phone, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }

    private function named(string $username): User
    {
        return Tenancy::runFor($this->company, function () use ($username) {
            $this->user->update(['username' => $username]);

            return $this->user->refresh();
        });
    }

    public function test_the_login_page_asks_for_the_username(): void
    {
        $this->get($this->host().'/login')
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertDontSee('name="phone"', false)
            ->assertSee('الزاجل');
    }

    public function test_a_chosen_username_logs_in_however_its_letters_are_cased(): void
    {
        $this->named('Ali.Salam');
        $this->assertSame('ali.salam', $this->user->username);

        $this->post($this->host().'/login', ['username' => ' ALI.salam ', 'password' => 'password'])
            ->assertRedirect($this->host());

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_account_without_a_chosen_name_logs_in_with_its_phone_even_in_arabic_digits(): void
    {
        $this->assertSame($this->user->phone, $this->user->username);

        $this->post($this->host().'/login', [
            'username' => $this->arabicDigits($this->user->phone), 'password' => 'password',
        ])->assertRedirect($this->host());

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_phone_no_longer_logs_in_once_a_name_is_chosen(): void
    {
        $this->named('ali.salam');

        $this->post($this->host().'/login', ['username' => $this->user->phone, 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_another_way_of_writing_the_name_does_not_reset_the_attempts(): void
    {
        $this->named('ali.salam');

        foreach (range(1, 5) as $ignored) {
            $this->post($this->host().'/login', ['username' => 'ali.salam', 'password' => 'wrong']);
        }

        // كلمة السرّ الصحيحة هذه المرّة، والاسم بحروفٍ كبيرة — والحساب موقوفٌ مع ذلك
        $this->post($this->host().'/login', ['username' => 'ALI.SALAM', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertStringContainsString('محاولات كثيرة', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_letters_the_normaliser_does_not_know_never_reach_the_database(): void
    {
        $this->named('ali.salam');

        // عرضيّة العرض: MySQL تساويها بالحروف اللاتينية، والموحِّد لا يعرفها
        $fullwidth = mb_convert_kana('ali.salam', 'R', 'UTF-8');
        $this->assertNotSame('ali.salam', $fullwidth);

        $this->post($this->host().'/login', ['username' => $fullwidth, 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_a_company_username_does_not_open_another_company(): void
    {
        $this->named('ali.salam');
        $other = $this->makeCompany('barq', 'برق');

        $this->post('http://barq.'.config('zajel.tenant_domain').'/login', [
            'username' => 'ali.salam', 'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertNotNull($other);
    }

    public function test_the_platform_login_takes_the_username_and_arabic_digits_too(): void
    {
        $admin = Tenancy::runAsPlatform(fn () => User::create([
            'company_id' => null,
            'name'       => 'مشغّل المنصّة',
            'phone'      => '07700000009',
            'password'   => 'password',
            'role'       => UserRole::PlatformAdmin,
            'is_active'  => true,
        ]));

        $this->get('/admin/login')->assertOk()->assertSee('name="username"', false);

        $this->post('/admin/login', [
            'username' => $this->arabicDigits($admin->phone), 'password' => 'password',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }
}
