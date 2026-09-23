<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحات الخطأ بالعربية. عرض لارافيل الافتراضي يكتب «Not Found» و«Page
 * Expired» بالإنجليزية، ويُسقط رسالة abort() العربية التي تشرح ما جرى.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_address_explains_itself_in_arabic(): void
    {
        $this->get('http://nope.'.config('zajel.tenant_domain').'/shipments')
            ->assertNotFound()
            ->assertSee('لم يُحدَّد النظام المطلوب')
            ->assertDontSee('Not Found');
    }

    public function test_an_internal_english_message_is_never_shown(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');

        // شحنة لا وجود لها: الرسالة الداخلية «No query results for model…»
        $this->actingAs($this->makeUser($company))
            ->get('http://zajel.'.config('zajel.tenant_domain').'/shipments/999999')
            ->assertNotFound()
            ->assertSee('الصفحة غير موجودة')
            ->assertDontSee('No query results')
            ->assertDontSee('App\\Models', false);
    }

    public function test_a_forbidden_page_is_arabic(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');

        $this->actingAs($this->makeUser($company))
            ->get('/admin')
            ->assertForbidden()
            ->assertSee('غير مصرّح')
            ->assertDontSee('Forbidden');
    }

    public function test_home_on_an_error_page_is_the_viewers_own_home(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($company);
        $user = \App\Support\Tenancy\Tenancy::runFor($company, fn () => \App\Models\User::create([
            'name' => 'تاجر', 'phone' => '07790001234', 'password' => 'password',
            'role' => \App\Enums\UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));

        // التاجر لا يفتح لوحة الموظّفين: «الرئيسية» تعيده إلى بوابته لا إلى 403 أخرى
        $this->actingAs($user)
            ->get('http://zajel.'.config('zajel.tenant_domain').'/')
            ->assertForbidden()
            ->assertSee('href="http://zajel.'.config('zajel.tenant_domain').'/portal"', false);
    }
}
