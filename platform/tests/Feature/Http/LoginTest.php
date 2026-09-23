<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدخول بالهاتف: رقمٌ واحد مهما كُتب، ومحاولاتٌ معدودة على الحساب.
 *
 * MySQL (utf8mb4_unicode_ci) ترى الأرقام العربية والفارسية وعرضية
 * العرض والهندية مساويةً للاتينية: «٠٧٧٠…» = «0770…». وكان مفتاح
 * المحاولات النصَّ كما كُتب، فلكل صيغةٍ خمسُ محاولاتٍ جديدة. هذه
 * الاختبارات تُسقط الثغرة على المحرّكين: على MySQL تدخل الصيغة الأخرى
 * متجاوزةً الحدّ، وعلى SQLite لا يُعرف الرقم العربي أصلاً.
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

    public function test_a_phone_typed_in_arabic_digits_logs_in(): void
    {
        $this->post($this->host().'/login', [
            'phone' => $this->arabicDigits($this->user->phone), 'password' => 'password',
        ])->assertRedirect($this->host());

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_another_way_of_writing_the_number_does_not_reset_the_attempts(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->post($this->host().'/login', ['phone' => $this->user->phone, 'password' => 'wrong']);
        }

        // كلمة السرّ الصحيحة هذه المرّة — والحساب موقوفٌ مع ذلك
        $this->post($this->host().'/login', [
            'phone' => $this->arabicDigits($this->user->phone), 'password' => 'password',
        ])->assertSessionHasErrors('phone');

        $this->assertStringContainsString('محاولات كثيرة', session('errors')->first('phone'));
        $this->assertGuest();
    }

    public function test_digits_the_normaliser_does_not_know_never_reach_the_database(): void
    {
        // عرضيّة العرض: MySQL تساويها بالرقم، والموحِّد لا يعرفها
        $fullwidth = mb_convert_kana($this->user->phone, 'N', 'UTF-8');
        $this->assertNotSame($this->user->phone, $fullwidth);

        $this->post($this->host().'/login', ['phone' => $fullwidth, 'password' => 'password'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_the_platform_login_reads_arabic_digits_too(): void
    {
        $admin = \App\Support\Tenancy\Tenancy::runAsPlatform(fn () => User::create([
            'company_id' => null,
            'name'       => 'مشغّل المنصّة',
            'phone'      => '07700000009',
            'password'   => 'password',
            'role'       => UserRole::PlatformAdmin,
            'is_active'  => true,
        ]));

        $this->post('/admin/login', [
            'phone' => $this->arabicDigits($admin->phone), 'password' => 'password',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }
}
